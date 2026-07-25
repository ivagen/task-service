<?php declare(strict_types=1);

namespace tests\Feature;

use Yii;
use tests\TestCase;
use app\models\Task;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the OpenAPI description against drifting away from the code.
 *
 * Every route the application serves must be documented, and every documented
 * route must exist — a mismatch fails CI instead of silently shipping a wrong
 * contract.
 */
class OpenApiSpecTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../../docs/openapi.yaml';

    /** @return array<string, mixed> */
    private function spec(): array
    {
        $this->assertFileExists(self::SPEC);

        return Yaml::parseFile(self::SPEC);
    }

    /**
     * "GET /api/v1/tasks/{id}" for each rule configured in urlManager.
     *
     * @return list<string>
     */
    private function routesFromUrlManager(): array
    {
        $config = require dirname(__DIR__, 2) . '/config/test.php';
        $routes = [];

        foreach (array_keys($config['components']['urlManager']['rules']) as $rule) {
            [$method, $pattern] = explode(' ', (string)$rule, 2);

            // 'api/v1/tasks/<id:\d+>' -> '/api/v1/tasks/{id}'
            $path = '/' . preg_replace('/<(\w+):[^>]+>/', '{$1}', $pattern);

            $routes[] = strtolower($method) . ' ' . $path;
        }

        sort($routes);

        return $routes;
    }

    /** @return list<string> */
    private function routesFromSpec(): array
    {
        $routes = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if ($method === 'parameters') {
                    continue;
                }
                $routes[] = $method . ' ' . $path;
            }
        }

        sort($routes);

        return $routes;
    }

    public function test_every_route_is_documented_and_every_documented_route_exists(): void
    {
        $this->assertSame(
            $this->routesFromUrlManager(),
            $this->routesFromSpec(),
            'docs/openapi.yaml is out of sync with the urlManager rules.',
        );
    }

    public function test_documented_statuses_match_the_model(): void
    {
        $spec = $this->spec();

        $this->assertSame(
            Task::STATUSES,
            $spec['components']['schemas']['TaskStatus']['enum'],
        );
        $this->assertSame(
            Task::PRIORITIES,
            $spec['components']['schemas']['TaskPriority']['enum'],
        );
    }

    public function test_documented_task_fields_match_the_serialised_payload(): void
    {
        $created = $this->post('/api/v1/tasks', ['title' => 'Documented']);
        $this->assertSame(201, $created['status']);

        $documented = array_keys($this->spec()['components']['schemas']['Task']['properties']);

        $this->assertEqualsCanonicalizing(
            array_keys($created['body']['data']),
            $documented,
            'The Task schema does not match what the API actually returns.',
        );
    }

    public function test_documented_pagination_meta_matches_the_response(): void
    {
        $res = $this->get('/api/v1/tasks');

        $documented = array_keys($this->spec()['components']['schemas']['PaginationMeta']['properties']);

        $this->assertEqualsCanonicalizing(array_keys($res['body']['meta']), $documented);
    }

    public function test_documented_per_page_default_matches_the_implementation(): void
    {
        $perPage = null;

        foreach ($this->spec()['paths']['/api/v1/tasks']['get']['parameters'] as $parameter) {
            if ($parameter['name'] === 'per_page') {
                $perPage = $parameter['schema']['default'];
            }
        }

        $this->assertSame(\app\models\TaskSearch::DEFAULT_PER_PAGE, $perPage);
        $this->assertSame($perPage, $this->get('/api/v1/tasks')['body']['meta']['per_page']);
    }

    public function test_error_envelope_matches_the_documented_shape(): void
    {
        $documented = array_keys(
            $this->spec()['components']['schemas']['Error']['properties']['error']['properties'],
        );

        $actual = array_keys($this->get('/api/v1/tasks/999999')['body']['error']);

        $this->assertEqualsCanonicalizing($documented, $actual);
    }

    public function test_security_scheme_is_declared(): void
    {
        $spec = $this->spec();

        $this->assertSame('http', $spec['components']['securitySchemes']['bearerAuth']['type']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['bearerAuth']['scheme']);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
    }

    public function test_health_endpoints_are_documented_as_public(): void
    {
        $spec = $this->spec();

        foreach (['/api/v1/health', '/api/v1/ready'] as $path) {
            $this->assertSame([], $spec['paths'][$path]['get']['security'], "{$path} must opt out of auth");

            // ...and actually be reachable without a token.
            $this->assertNotSame(401, $this->get($path, auth: false)['status']);
        }
    }

    public function test_yii_is_configured_with_the_same_routes_in_web_and_test(): void
    {
        // The drift check reads config/test.php; make sure it mirrors web.php.
        $web = require dirname(__DIR__, 2) . '/config/web.php';
        $test = require dirname(__DIR__, 2) . '/config/test.php';

        $this->assertSame(
            array_keys($web['components']['urlManager']['rules']),
            array_keys($test['components']['urlManager']['rules']),
        );

        Yii::getLogger()->flush();
    }
}

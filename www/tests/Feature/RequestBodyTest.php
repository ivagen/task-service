<?php declare(strict_types=1);

namespace tests\Feature;

use tests\TestCase;

/**
 * Covers the request body as it actually arrives on the wire, so the
 * content-type parser runs instead of being bypassed by the test harness.
 */
class RequestBodyTest extends TestCase
{
    public function test_malformed_json_returns_400(): void
    {
        $res = $this->postRaw('/api/v1/tasks', '{"title": "Broken"');

        $this->assertSame(400, $res['status']);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(400, $res['body']['error']['code']);
    }

    public function test_malformed_json_response_carries_a_request_id(): void
    {
        $res = $this->postRaw('/api/v1/tasks', 'not json at all');

        $this->assertSame(400, $res['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $res['body']['error']['request_id']);
    }

    public function test_json_scalar_instead_of_object_does_not_crash(): void
    {
        // Valid JSON, wrong shape: must be a validation failure, not a 500.
        $res = $this->postRaw('/api/v1/tasks', '"just a string"');

        $this->assertContains($res['status'], [400, 422], 'expected a client error, got ' . $res['status']);
        $this->assertLessThan(500, $res['status']);
    }

    public function test_empty_raw_body_is_treated_as_no_fields(): void
    {
        $res = $this->postRaw('/api/v1/tasks', '');

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('title', $res['body']['error']['message']);
    }

    public function test_empty_json_object_is_treated_as_no_fields(): void
    {
        $res = $this->postRaw('/api/v1/tasks', '{}');

        $this->assertSame(422, $res['status']);
        $this->assertArrayHasKey('title', $res['body']['error']['message']);
    }

    public function test_well_formed_json_still_works_through_the_parser(): void
    {
        $res = $this->postRaw('/api/v1/tasks', json_encode([
            'title' => 'Parsed for real',
            'priority' => 2,
        ]));

        $this->assertSame(201, $res['status']);
        $this->assertSame('Parsed for real', $res['body']['data']['title']);
        $this->assertSame(2, $res['body']['data']['priority']);
    }

    public function test_malformed_json_is_rejected_before_touching_the_database(): void
    {
        $this->postRaw('/api/v1/tasks', '{"title": ');

        $this->assertSame(0, $this->get('/api/v1/tasks')['body']['meta']['total']);
    }
}

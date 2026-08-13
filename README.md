# Task Service

Yii2 REST microservice for task management. Authorization via Laravel Passport.

## Requirements

- Docker and Docker Compose
- A running auth_service (Laravel Passport) on port 8000

## Configuration

There are two `.env` files with different roles:

| File | Who reads it | When it's needed |
|------|--------------|------------------|
| `.env` (root) | Docker Compose — both for `${...}` and for passing variables into the `app` container | always when running via Docker |
| `www/.env` | Dotenv inside PHP | only for running **without** Docker |

Under Docker the single source of truth is the root `.env`. Real environment
variables always take priority over `www/.env`, so there is no need to duplicate them.

Application variables are read through `app\components\Env`, which looks at
`$_ENV`, `$_SERVER` and `getenv()` — so both the `.env` file and `environment:` in
Compose work the same way.

## Setup

### 1. Clone the repository and enter the directory

```bash
git clone <repo-url> task-service
cd task-service
```

### 2. Create the .env file

```bash
make env        # copies .env.example → .env
```

Key values are described in `.env.example`. At minimum, check these:

```
DB_NAME=task_service
DB_USER=task_service
DB_PASSWORD=secret
CACHE_DRIVER=redis
AUTH_SERVICE_URL=http://host.docker.internal:8000
```

### 3. First run (a single command)

```bash
make bootstrap
```

Performs: image build, container startup (waiting for the MySQL and Redis
healthchecks), `composer install` and migrations. `sudo` is not required —
`vendor/` and `runtime/` live in named volumes.

### 4. The service is available at http://localhost:8002

Check: `curl http://localhost:8002/api/v1/health`

### Connecting to auth-service

By default task-service reaches auth-service through
`host.docker.internal` (works on both Docker Desktop and Linux thanks to
`extra_hosts: host-gateway`).

If auth-service also runs in Docker, it is better to attach both services to a
shared network:

```bash
docker network ls                     # find the auth-service network
# in .env:
#   AUTH_NETWORK=auth_service_default
#   AUTH_SERVICE_URL=http://auth_service_nginx:8000

make up COMPOSE_FILES="-f docker-compose.yml -f docker-compose.auth-network.yml"
```

### Production

`docker-compose.yml` is the development configuration (bind-mounted code, dev
dependencies). Production uses a separate file that does not mount the source
code and builds the image from the `prod` stage (`composer install --no-dev`,
`--classmap-authoritative` autoloading, php-fpm running as `www-data`):

```bash
make prod-build
```

---

## Make commands

| Command | Description |
|---------|-------------|
| `make bootstrap` | First run: build + `composer install` + migrations |
| `make build` | Rebuild the Docker image and start containers |
| `make up` | Start containers |
| `make down` | Stop containers |
| `make restart` | Restart containers |
| `make ps` | Container status |
| `make migrate` | Run migrations (`php yii migrate`) |
| `make test` | PHPUnit (Feature, SQLite) inside the container |
| `make test-integration` | PHPUnit against the stack's MySQL and Redis |
| `make coverage` | PHPUnit with the coverage threshold |
| `make analyse` | PHPStan |
| `make openapi` | Validate `docs/openapi.yaml` |
| `make cs-check` / `make cs-fix` | PHP CS Fixer |
| `make shell` | Open bash in the app container |
| `make logs` | Stream logs from all containers |
| `make env` | Create the root `.env` from `.env.example` |
| `make prod-build` | Build and start the production configuration |

---

## Health checks

| Endpoint | Description |
|----------|-------------|
| `GET /api/v1/health` | liveness — the process is alive, no authorization required |
| `GET /api/v1/ready` | readiness — checks the DB and cache, `503` if something is unavailable |

---

## API Endpoints

All requests require the `Authorization: Bearer <token>` header.

### Get the task list

```bash
curl -X GET "http://localhost:8002/api/v1/tasks" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

With filters:

```bash
curl -X GET "http://localhost:8002/api/v1/tasks?status=todo&priority=2&page=1&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Search:

```bash
curl -X GET "http://localhost:8002/api/v1/tasks?search=buy" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

With a date range:

```bash
curl -X GET "http://localhost:8002/api/v1/tasks?due_date_from=2026-04-01&due_date_to=2026-04-30" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Create a task

```bash
curl -X POST "http://localhost:8002/api/v1/tasks" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Buy milk",
    "description": "2 liters",
    "status": "todo",
    "priority": 1,
    "due_date": "2026-04-15"
  }'
```

### Get a task by ID

```bash
curl -X GET "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Update a task

```bash
curl -X PUT "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_progress",
    "priority": 2
  }'
```

### Delete a task

```bash
curl -X DELETE "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Returns `204 No Content` with an empty body.

---

## List parameters

| Parameter | Values | Invalid value |
|-----------|--------|---------------|
| `status` | `todo`, `in_progress`, `done` | `422` |
| `priority` | `1`, `2`, `3` | `422` |
| `due_date_from`, `due_date_to` | `Y-m-d`; `from` cannot be later than `to` | `422` |
| `search` | up to 255 characters, searches in `title` and `description` | `422` |
| `page` | integer ≥ 1 (default `1`) | `422` |
| `per_page` | integer ≥ 1 (default `20`); values above `100` are **capped** at `100` | `422` only for non-numeric or `< 1` |

The list is always sorted by `created_at DESC, id DESC` — a deterministic order,
without which pages could duplicate or skip records.

Unknown query parameters are ignored.

---

## Rate limiting

`60` requests per user per `60` seconds (configurable via `RATE_LIMIT`
and `RATE_LIMIT_WINDOW`). The window is fixed: the TTL is set only by the first
request in the window, so an active client does not block itself indefinitely.

Every response includes `X-RateLimit-Limit` and `X-RateLimit-Remaining`;
a `429` response additionally includes `Retry-After` in seconds.

With `CACHE_DRIVER=redis` the counter is incremented atomically (`INCR`). With
the file cache there is no atomicity — acceptable only for local development.

---

## Token caching

A validated Passport token is cached for `AUTH_CACHE_TTL` seconds (default
`60`). This means that **a revoked token stays valid for task-service until
that TTL expires** — a deliberate trade-off between latency and
consistency. Lower `AUTH_CACHE_TTL` or set it to `0` to make revocation take
effect immediately (at the cost of a request to auth-service on every API call).

If auth-service is unavailable (network error, timeout, `5xx`), the API
returns `503` rather than `401` — so the client does not mistake an outage for an
invalid token.

### Retries

Only failures that provably had no effect on the auth-service side are
retried: connection setup errors and `502/503/504`.

Never retried:

- `401` — a final answer; retrying would only burn the budget
- `500` — not a sign of a transient problem
- read timeout — the attempt has already consumed most of the budget, and there
  is no guarantee that the request did not arrive

| Variable | Default | What it does |
|----------|---------|--------------|
| `AUTH_RETRIES` | `1` | extra attempts after the first; `0` disables them |
| `AUTH_RETRY_DELAY_MS` | `100` | pause between attempts |
| `AUTH_TOTAL_TIMEOUT` | `5` | **hard ceiling for the entire check including retries** |

`AUTH_TOTAL_TIMEOUT` is what bounds the worst case. Token validation sits on the
critical path of every request, so a retry runs only if it fits in the
remaining budget; otherwise the service returns `503` right away.

---

## Logs

One JSON object per line, on **stderr** — php-fpm sends worker stdout to
`/dev/null`, while stderr reaches `docker logs` and the centralized
collector.

```json
{"timestamp":"2026-07-25T13:55:23.544Z","level":"error","service":"task-service","category":"access","event":"request","method":"GET","path":"/api/v1/tasks","status":503,"duration_ms":253,"request_id":"28416a16…"}
```

Every request produces a `category=access` line with the method, path, status and
duration; `5xx` is written at the `error` level so it reaches alerting.
The `/api/v1/health` and `/api/v1/ready` probes are excluded, otherwise they
would drown out everything else.

Calls to auth-service are logged separately (`category=passport`) with the number
of attempts and the duration:

```json
{"level":"error","category":"passport","event":"auth_service_call","outcome":"unavailable","attempts":2,"duration_ms":226,"request_id":"28416a16…"}
```

The `request_id` is shared by all lines of a single request **and** by the error
body the client received — so an ID from a user complaint leads to the full
picture.

The logs deliberately do **not** dump `$GLOBALS`: the default behavior of
`yii\log\Target` includes `$_SERVER`, which inside the container holds `DB_PASSWORD`
and other secrets.

---

## Response format

**Successful response (a single task):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": 5,
    "title": "Buy milk",
    "description": "2 liters",
    "status": "todo",
    "priority": 1,
    "due_date": "2026-04-15",
    "created_at": 1743379200,
    "updated_at": 1743379200
  }
}
```

**List:**
```json
{
  "success": true,
  "data": [...],
  "meta": {
    "total": 50,
    "page": 1,
    "per_page": 20,
    "total_pages": 3
  }
}
```

**Error:**
```json
{
  "success": false,
  "error": {
    "code": 401,
    "message": "Unauthorized",
    "request_id": "6f1c0f6e6b6d4f0a9c1e2d3b4a5f6071"
  }
}
```

The `request_id` is duplicated in the `X-Request-Id` header and in the logs. If a
client sends its own `X-Request-Id`, it is used for end-to-end
correlation.

### Error codes

| Code | When |
|------|------|
| `401` | no token or the token is invalid |
| `404` | the task does not exist **or** belongs to another user |
| `422` | validation error in the request body or query parameters |
| `429` | request limit exceeded |
| `500` | internal error |
| `503` | auth-service or a dependency is unavailable |

Another user's task deliberately returns `404` rather than `403`: otherwise the
response code would reveal which IDs exist for another user.

For `500` the client only receives `Internal Server Error`. The full stack trace
is written to the log with the same `request_id`; details make it into the
response only when `YII_DEBUG=true`.

---

## API documentation

The formal specification is `docs/openapi.yaml` (OpenAPI 3.0.3): all endpoints,
request and response schemas, `bearerAuth`, the `401/404/422/429/500/503` codes,
pagination and filtering rules.

```bash
npx @redocly/cli lint              # validation (config in redocly.yaml)
npx @redocly/cli preview-docs      # interactive preview
```

The spec cannot go stale unnoticed:
`www/tests/Feature/OpenApiSpecTest.php` checks it against the real `urlManager`
routes, response fields, model enums and default
values. A mismatch fails the tests.

---

## Tests

```bash
make test                      # in the container (SQLite)
cd www && composer test        # locally, Feature only
cd www && composer analyse     # PHPStan level 5
```

Two levels:

| Suite | Dependencies | When |
|-------|--------------|------|
| `Feature` | SQLite in-memory | always; fast |
| `Integration` | MySQL 8 + Redis | requires `TEST_DB_DSN` / `TEST_REDIS_HOST`, otherwise skipped |

The integration level runs the **real migrations** and catches what SQLite does
not reproduce: `ENUM`, strict mode, `utf8mb4` collation, actual indexes — and with
Redis it verifies the atomic `INCR` and the TTL behavior that the cache fallback
cannot cover.

```bash
cd www
TEST_DB_DSN="mysql:host=127.0.0.1;port=3307;dbname=task_service_test" \
TEST_DB_USER=root TEST_DB_PASSWORD=root \
TEST_REDIS_HOST=127.0.0.1 TEST_REDIS_PORT=6380 TEST_REDIS_DB=15 \
composer test:integration
```

`TEST_REDIS_DB` is kept separate from the working database and is cleared with
`FLUSHDB` before every test.

### Coverage threshold

```bash
cd www && composer test:coverage    # requires pcov or xdebug
```

The minimum is **85%** of lines, defined once in `composer.json`
(`test:coverage`); CI calls the same script. The current value is around
91%. `tests/coverage-check.php` also fails when the coverage driver is
missing entirely, so that an empty report does not pass as success.

---

## CI

`.github/workflows/ci.yml` — four jobs:

| Job | What it does |
|-----|--------------|
| `quality` | `composer validate`, install, syntax check, PHP CS Fixer, PHPStan, PHPUnit with the coverage threshold (SQLite), `composer audit` |
| `integration` | brings up MySQL 8 and Redis, runs all suites against them and verifies they were **not** skipped |
| `openapi` | `redocly lint` + bundle |
| `docker` | builds the production image and scans it with Trivy (HIGH/CRITICAL) |

---

## Stop the service

```bash
make down
```

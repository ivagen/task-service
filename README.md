# Task Service

Yii2 REST мікросервіс для управління завданнями. Авторизація через Laravel Passport.

## Вимоги

- Docker та Docker Compose
- Запущений auth_service (Laravel Passport) на порту 8000

## Конфігурація

Є два `.env` файли з різними ролями:

| Файл | Хто читає | Коли потрібен |
|------|-----------|---------------|
| `.env` (корінь) | Docker Compose — і для `${...}`, і для передачі змінних у контейнер `app` | завжди при запуску через Docker |
| `www/.env` | Dotenv усередині PHP | тільки для запуску **без** Docker |

Під Docker єдиним джерелом істини є кореневий `.env`. Реальні змінні оточення
завжди мають пріоритет над `www/.env`, тому дублювати їх не треба.

Змінні застосунку читаються через `app\components\Env`, який дивиться в
`$_ENV`, `$_SERVER` і `getenv()` — тому і `.env`-файл, і `environment:` у
Compose працюють однаково.

## Налаштування

### 1. Клонуй репозиторій та перейди в директорію

```bash
git clone <repo-url> task-service
cd task-service
```

### 2. Створи .env файл

```bash
make env        # копіює .env.example → .env
```

Ключові значення описані в `.env.example`. Мінімум, що варто перевірити:

```
DB_NAME=task_service
DB_USER=task_service
DB_PASSWORD=secret
CACHE_DRIVER=redis
AUTH_SERVICE_URL=http://host.docker.internal:8000
```

### 3. Перший запуск (одна команда)

```bash
make bootstrap
```

Виконує: build образу, запуск контейнерів (з очікуванням healthcheck-ів
MySQL і Redis), `composer install` та міграції. `sudo` не потрібен —
`vendor/` і `runtime/` живуть у named volumes.

### 4. Сервіс доступний на http://localhost:8002

Перевірка: `curl http://localhost:8002/api/v1/health`

### Зв'язок з auth-service

За замовчуванням task-service ходить до auth-service через
`host.docker.internal` (працює і на Docker Desktop, і на Linux завдяки
`extra_hosts: host-gateway`).

Якщо auth-service теж у Docker, краще підключити обидва сервіси до спільної
мережі:

```bash
docker network ls                     # знайти мережу auth-service
# у .env:
#   AUTH_NETWORK=auth_service_default
#   AUTH_SERVICE_URL=http://auth_service_nginx:8000

make up COMPOSE_FILES="-f docker-compose.yml -f docker-compose.auth-network.yml"
```

### Production

`docker-compose.yml` — це development-конфігурація (bind mount коду, dev-залежності).
Для production використовується окремий файл, який не монтує вихідний код і
збирає образ зі стадії `prod` (`composer install --no-dev`, автозавантаження
`--classmap-authoritative`, php-fpm від `www-data`):

```bash
make prod-build
```

---

## Make команди

| Команда | Опис |
|---------|------|
| `make bootstrap` | Перший запуск: build + `composer install` + міграції |
| `make build` | Rebuild Docker образу і запуск контейнерів |
| `make up` | Запустити контейнери |
| `make down` | Зупинити контейнери |
| `make restart` | Перезапустити контейнери |
| `make ps` | Статус контейнерів |
| `make migrate` | Виконати міграції (`php yii migrate`) |
| `make test` | PHPUnit (Feature, SQLite) у контейнері |
| `make test-integration` | PHPUnit проти MySQL і Redis зі стеку |
| `make coverage` | PHPUnit із порогом покриття |
| `make analyse` | PHPStan |
| `make openapi` | Валідація `docs/openapi.yaml` |
| `make cs-check` / `make cs-fix` | PHP CS Fixer |
| `make shell` | Відкрити bash в контейнері app |
| `make logs` | Стрімити логи всіх контейнерів |
| `make env` | Створити кореневий `.env` з `.env.example` |
| `make prod-build` | Зібрати й запустити production-конфігурацію |

---

## Health checks

| Endpoint | Опис |
|----------|------|
| `GET /api/v1/health` | liveness — процес живий, авторизація не потрібна |
| `GET /api/v1/ready` | readiness — перевіряє БД і кеш, `503` якщо щось недоступне |

---

## API Endpoints

Всі запити вимагають заголовок `Authorization: Bearer <token>`.

### Отримати список завдань

```bash
curl -X GET "http://localhost:8002/api/v1/tasks" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

З фільтрами:

```bash
curl -X GET "http://localhost:8002/api/v1/tasks?status=todo&priority=2&page=1&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Пошук:

```bash
curl -X GET "http://localhost:8002/api/v1/tasks?search=купити" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

З діапазоном дат:

```bash
curl -X GET "http://localhost:8002/api/v1/tasks?due_date_from=2026-04-01&due_date_to=2026-04-30" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Створити завдання

```bash
curl -X POST "http://localhost:8002/api/v1/tasks" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Купити молоко",
    "description": "2 літри",
    "status": "todo",
    "priority": 1,
    "due_date": "2026-04-15"
  }'
```

### Отримати завдання за ID

```bash
curl -X GET "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Оновити завдання

```bash
curl -X PUT "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "in_progress",
    "priority": 2
  }'
```

### Видалити завдання

```bash
curl -X DELETE "http://localhost:8002/api/v1/tasks/1" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

Повертає `204 No Content` з порожнім тілом.

---

## Параметри списку

| Параметр | Значення | Некоректне значення |
|----------|----------|---------------------|
| `status` | `todo`, `in_progress`, `done` | `422` |
| `priority` | `1`, `2`, `3` | `422` |
| `due_date_from`, `due_date_to` | `Y-m-d`; `from` не може бути пізніше за `to` | `422` |
| `search` | до 255 символів, шукає в `title` і `description` | `422` |
| `page` | ціле ≥ 1 (за замовчуванням `1`) | `422` |
| `per_page` | ціле ≥ 1 (за замовчуванням `20`); значення понад `100` **обрізається** до `100` | `422` лише для нечислових або `< 1` |

Список завжди сортується `created_at DESC, id DESC` — детермінований порядок,
без якого сторінки могли б дублювати або пропускати записи.

Невідомі query-параметри ігноруються.

---

## Обмеження швидкості

`60` запитів на користувача за `60` секунд (налаштовується через `RATE_LIMIT`
і `RATE_LIMIT_WINDOW`). Вікно фіксоване: TTL встановлюється лише першим
запитом у вікні, тому активний клієнт не блокує сам себе нескінченно.

Кожна відповідь містить `X-RateLimit-Limit` і `X-RateLimit-Remaining`;
відповідь `429` додатково містить `Retry-After` у секундах.

З `CACHE_DRIVER=redis` лічильник інкрементується атомарно (`INCR`). З
файловим кешем атомарності немає — це прийнятно лише для локальної розробки.

---

## Кешування токенів

Валідований Passport-токен кешується на `AUTH_CACHE_TTL` секунд (за
замовчуванням `60`). Це означає, що **відкликаний токен залишається дійсним
для task-service до кінця цього TTL** — свідомий компроміс між latency та
консистентністю. Зменш `AUTH_CACHE_TTL` або встанови `0`, щоб відкликання
діяло миттєво (ціною запиту до auth-service на кожен виклик API).

Якщо auth-service недоступний (мережева помилка, timeout, `5xx`), API
повертає `503`, а не `401` — щоб клієнт не сприйняв аварію як невалідний
токен.

### Повтори (retry)

Повторюються **лише** ті збої, які гарантовано не мали ефекту на боці
auth-service: помилка встановлення з'єднання і `502/503/504`.

Не повторюються ніколи:

- `401` — це остаточна відповідь, повтор лише витратить бюджет
- `500` — не є ознакою тимчасовості
- read timeout — спроба вже з'їла більшу частину бюджету, і немає гарантії,
  що запит не дійшов

| Змінна | За замовчуванням | Що робить |
|--------|------------------|-----------|
| `AUTH_RETRIES` | `1` | додаткові спроби після першої; `0` вимикає |
| `AUTH_RETRY_DELAY_MS` | `100` | пауза між спробами |
| `AUTH_TOTAL_TIMEOUT` | `5` | **жорсткий стелаж на всю перевірку разом із повторами** |

`AUTH_TOTAL_TIMEOUT` — це те, що обмежує найгірший випадок. Перевірка токена
стоїть у критичному шляху кожного запиту, тому повтор виконується лише якщо
встигає в залишок бюджету; інакше сервіс одразу віддає `503`.

---

## Логи

Один JSON-об'єкт на рядок, у **stderr** — php-fpm надсилає stdout воркерів у
`/dev/null`, а stderr доходить до `docker logs` і до централізованого
збирача.

```json
{"timestamp":"2026-07-25T13:55:23.544Z","level":"error","service":"task-service","category":"access","event":"request","method":"GET","path":"/api/v1/tasks","status":503,"duration_ms":253,"request_id":"28416a16…"}
```

Кожен запит дає рядок `category=access` з методом, шляхом, статусом і
тривалістю; `5xx` пишеться на рівні `error`, щоб потрапляти в алерти.
Проби `/api/v1/health` і `/api/v1/ready` виключені, інакше вони заглушили б
решту.

Виклики auth-service логуються окремо (`category=passport`) із кількістю
спроб і тривалістю:

```json
{"level":"error","category":"passport","event":"auth_service_call","outcome":"unavailable","attempts":2,"duration_ms":226,"request_id":"28416a16…"}
```

`request_id` спільний для всіх рядків одного запиту **і** для тіла помилки,
яке отримав клієнт — тобто за ID зі скарги користувача знаходиться повна
картина.

Логи навмисно **не** дамплять `$GLOBALS`: стандартна поведінка
`yii\log\Target` включає `$_SERVER`, а там у контейнері лежать `DB_PASSWORD`
та інші секрети.

---

## Формат відповідей

**Успішна відповідь (одне завдання):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "user_id": 5,
    "title": "Купити молоко",
    "description": "2 літри",
    "status": "todo",
    "priority": 1,
    "due_date": "2026-04-15",
    "created_at": 1743379200,
    "updated_at": 1743379200
  }
}
```

**Список:**
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

**Помилка:**
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

`request_id` дублюється в заголовку `X-Request-Id` і в логах. Якщо клієнт
надішле власний `X-Request-Id`, він буде використаний для наскрізної
кореляції.

### Коди помилок

| Код | Коли |
|-----|------|
| `401` | немає токена або токен невалідний |
| `404` | завдання не існує **або** належить іншому користувачу |
| `422` | помилка валідації тіла запиту чи query-параметрів |
| `429` | перевищено ліміт запитів |
| `500` | внутрішня помилка |
| `503` | auth-service або залежність недоступні |

Чуже завдання свідомо повертає `404`, а не `403`: інакше по коду відповіді
можна було б визначити, який ID існує в чужого користувача.

Для `500` клієнт отримує лише `Internal Server Error`. Повний стек
пишеться в лог із тим самим `request_id`; деталі потрапляють у відповідь
тільки при `YII_DEBUG=true`.

---

## API-документація

Формальна специфікація — `docs/openapi.yaml` (OpenAPI 3.0.3): усі endpoint-и,
схеми запитів і відповідей, `bearerAuth`, коди `401/404/422/429/500/503`,
правила pagination та filtering.

```bash
npx @redocly/cli lint              # валідація (конфіг у redocly.yaml)
npx @redocly/cli preview-docs      # інтерактивний перегляд
```

Специфікація не може «протухнути» непомітно:
`www/tests/Feature/OpenApiSpecTest.php` звіряє її з реальними маршрутами
`urlManager`, з полями відповіді, enum-ами моделі та значеннями за
замовчуванням. Розбіжність валить тести.

---

## Тести

```bash
make test                      # у контейнері (SQLite)
cd www && composer test        # локально, тільки Feature
cd www && composer analyse     # PHPStan level 5
```

Два рівні:

| Suite | Залежності | Коли |
|-------|-----------|------|
| `Feature` | SQLite in-memory | завжди; швидко |
| `Integration` | MySQL 8 + Redis | вимагає `TEST_DB_DSN` / `TEST_REDIS_HOST`, інакше скіпається |

Integration-рівень прогонює **реальні міграції** і ловить те, чого SQLite не
відтворює: `ENUM`, strict mode, `utf8mb4` collation, фактичні індекси — а з
Redis перевіряє атомарний `INCR` та поведінку TTL, яку fallback на кеші
покрити не може.

```bash
cd www
TEST_DB_DSN="mysql:host=127.0.0.1;port=3307;dbname=task_service_test" \
TEST_DB_USER=root TEST_DB_PASSWORD=root \
TEST_REDIS_HOST=127.0.0.1 TEST_REDIS_PORT=6380 TEST_REDIS_DB=15 \
composer test:integration
```

`TEST_REDIS_DB` використовується окремо від робочої бази і чиститься
`FLUSHDB` перед кожним тестом.

### Поріг покриття

```bash
cd www && composer test:coverage    # потрібен pcov або xdebug
```

Мінімум — **85%** рядків, заданий один раз у `composer.json`
(`test:coverage`); CI викликає той самий скрипт. Поточне значення — близько
91%. `tests/coverage-check.php` падає і тоді, коли драйвер покриття взагалі
відсутній, щоб порожній звіт не проходив як успіх.

---

## CI

`.github/workflows/ci.yml` — чотири задачі:

| Задача | Що робить |
|--------|-----------|
| `quality` | `composer validate`, install, syntax check, PHP CS Fixer, PHPStan, PHPUnit із порогом покриття (SQLite), `composer audit` |
| `integration` | піднімає MySQL 8 і Redis, прогонює всі suite-и проти них і перевіряє, що вони **не** скіпнулись |
| `openapi` | `redocly lint` + bundle |
| `docker` | збирає production-образ і сканує його Trivy (HIGH/CRITICAL) |

---

## Зупинити сервіс

```bash
make down
```
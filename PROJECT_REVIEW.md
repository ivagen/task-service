# Рев’ю проєкту Task Service

## Загальна оцінка

Проєкт має компактну й зрозумілу структуру, логічний поділ на контролер, моделі, компоненти авторизації та feature-тести. Базові CRUD-сценарії реалізовані, доступ до завдань обмежується користувачем, а відповіді API мають єдиний JSON-формат.

Перед використанням у production варто виправити конфігурацію середовища, обробку внутрішніх помилок, валідацію оновлення, сортування списку та rate limiter.

## Критичні та важливі зауваження

### 1. `.env` фактично не керує Docker-конфігурацією

README пропонує створювати `www/.env`, але Docker Compose підставляє змінні з кореневого `.env` або середовища shell. Частина значень також жорстко записана в `docker-compose.yml`.

Пов’язані місця:

- `docker-compose.yml:13`
- `Makefile:36`
- `README.md:19`

Можливі наслідки:

- `CACHE_DRIVER=redis` із `www/.env` не передається в контейнер, тому застосунок використовує `FileCache`;
- `AUTH_SERVICE_URL` із README ігнорується, оскільки Compose задає `http://auth_service_nginx:8000`;
- `DB_NAME`, `DB_USER` і `DB_PASSWORD` із `www/.env` не використовуються Docker Compose;
- auth-контейнер не підключений до мережі `task-service`, тому ім’я `auth_service_nginx` може не резолвитись.

Рекомендації:

- визначити єдине джерело Docker-змінних, наприклад кореневий `.env`;
- передавати runtime-змінні PHP-контейнеру через `env_file: ./www/.env` або явно через `environment`;
- прибрати жорстко записаний `AUTH_SERVICE_URL`;
- підключити task-service та auth-service до спільної external Docker network;
- синхронізувати README, `.env.example` і `docker-compose.yml`.

### 2. Оновлення дозволяє встановити прострочений `due_date`

Перевірка майбутньої дати застосовується тільки у сценарії `create`:

- `www/models/Task.php:31`
- `www/controllers/TaskController.php:76`

Створення завдання з минулою датою повертає `422`, але оновлення існуючого завдання такою самою датою проходить успішно.

Рекомендація: застосувати `validateDueDateFuture` також у сценарії `update`, якщо дата виконання завжди має бути сьогоднішньою або майбутньою.

### 3. Pagination не має стабільного сортування

У `www/models/TaskSearch.php:24` використовуються `limit` та `offset`, але запит не містить `ORDER BY`.

База даних не гарантує порядок записів без явного сортування. Під час створення або видалення завдань елементи можуть дублюватися між сторінками або пропускатися.

Рекомендоване базове сортування:

```php
$query->orderBy([
    'created_at' => SORT_DESC,
    'id' => SORT_DESC,
]);
```

Для великих обсягів даних можна розглянути cursor-based pagination.

### 4. Rate limiter має race condition і постійно продовжує TTL

Поточна реалізація знаходиться у `www/behaviors/PassportAuthBehavior.php:64`.

Проблеми:

- операції `get()` і `set()` не атомарні;
- паралельні запити можуть отримати однаковий counter і частково обійти ліміт;
- кожен дозволений запит повторно встановлює TTL у 60 секунд;
- після досягнення ліміту активний клієнт може залишатися заблокованим, доки повністю не припинить запити на одну хвилину;
- відповідь не містить `Retry-After` та інформації про залишок ліміту.

Рекомендації:

- використовувати атомарний Redis `INCR`;
- встановлювати `EXPIRE` лише після першого increment;
- або застосувати стандартний механізм rate limiting у Yii;
- додати `Retry-After`, `X-RateLimit-Limit` і `X-RateLimit-Remaining`;
- покрити паралельні й граничні сценарії тестами.

### 5. Production API розкриває внутрішні повідомлення exception

У `www/components/JsonErrorHandler.php:27` повідомлення будь-якого exception повертається клієнту:

```php
'message' => $exception->getMessage()
```

Для помилок `500` це може розкрити:

- SQL і структуру таблиць;
- файлові шляхи;
- назви внутрішніх компонентів;
- частини конфігурації;
- інші технічні деталі.

Рекомендації:

- логувати повний exception на сервері;
- у production для неочікуваних помилок повертати лише `Internal Server Error`;
- показувати деталі тільки при `YII_DEBUG=true`;
- додати correlation/request ID для пошуку помилки в логах.

### 6. `YII_DEBUG` і `YII_ENV` завантажуються до `.env`

У `www/web/index.php:3` константи читаються з `$_ENV` до підключення `www/config/bootstrap.php`, де запускається Dotenv.

Через це значення `YII_DEBUG` і `YII_ENV` із `www/.env` завантажуються надто пізно й не впливають на web-застосунок.

У console entrypoint `www/yii:7` Dotenv взагалі не підключається, а середовище завжди визначається як `dev`.

Рекомендації:

1. Підключити Composer autoload.
2. Завантажити `.env`.
3. Визначити `YII_DEBUG` і `YII_ENV`.
4. Підключити Yii.
5. Використати однаковий порядок у web та console entrypoints.

## Інші доопрацювання

### Обробка видалення

У `www/controllers/TaskController.php:97` результат `$task->delete()` ігнорується. Якщо база не виконає видалення, API все одно поверне успішну відповідь.

Рекомендація: перевіряти результат і повертати контрольовану помилку або кидати exception.

Для REST API також доречно повертати `204 No Content` замість:

```json
{
  "success": true,
  "data": null
}
```

### Розкриття існування чужих завдань

Метод `findOwnTask()` спочатку шукає завдання за ID, а потім повертає `403`, якщо воно належить іншому користувачу:

- `www/controllers/TaskController.php:102`

Це дозволяє відрізнити неіснуючий ID від ID чужого завдання.

Рекомендація: шукати запис одразу за `id` та `user_id` і в обох випадках повертати однаковий `404`.

```php
$task = Task::findOne([
    'id' => $id,
    'user_id' => $userId,
]);
```

### Валідація query-параметрів

Фільтри у `www/models/TaskSearch.php:37` застосовуються без окремої валідації.

Наприклад:

- невідомий `status`;
- `priority=abc`;
- некоректна дата;
- `due_date_from`, що більша за `due_date_to`;
- некоректні `page` або `per_page`.

Зараз такі значення переважно перетворюються або тихо дають порожній результат.

Рекомендація: зробити `TaskSearch` валідованою Yii-моделлю та повертати `422` або `400` з описом некоректних параметрів.

### Інтеграція з auth-service

У `www/components/PassportAuth.php` новий Guzzle client створюється на кожен cache miss.

Рекомендації:

- зареєструвати HTTP client через DI;
- винести timeout у конфігурацію;
- додати метрики latency та помилок;
- розрізняти невалідний токен і недоступність auth-сервісу;
- при недоступності auth-service повертати `503`, а не маскувати проблему як `401`;
- розглянути retry лише для безпечних тимчасових мережевих помилок;
- додати circuit breaker, якщо сервіс працюватиме під значним навантаженням.

### Кешування Passport token

Валідний токен кешується на 60 секунд. Відкликаний токен може залишатися чинним для task-service до завершення TTL.

Це може бути прийнятним performance/security компромісом, але його потрібно явно задокументувати.

Можливі альтернативи:

- коротший TTL;
- token introspection;
- локальна JWT-перевірка з ротацією ключів;
- подія інвалідації кешу при logout/revoke.

### Docker image і залежності

У Dockerfile використовуються:

- `php:8.5-fpm`;
- `composer:latest`.

Образ Composer не закріплений за конкретною версією або digest, тому збірка може змінитися без змін у репозиторії.

Також `composer install` запускається без `--no-dev`, тому production image містить PHPUnit і PHP CS Fixer.

Рекомендації:

- закріпити версії базових образів;
- для production використовувати `composer install --no-dev --classmap-authoritative`;
- налаштувати multi-stage build;
- запускати PHP-FPM від непривілейованого користувача;
- додати Docker healthcheck;
- перевіряти образ dependency/container scanner-ом.

### Bind mount перекриває результат Docker build

Dockerfile копіює код і встановлює залежності, але `docker-compose.yml` монтує:

```yaml
- ./www:/var/www
```

Bind mount повністю перекриває `/var/www`, створений під час build. Через це встановлені у Docker image залежності можуть стати недоступними, якщо на host немає `www/vendor`.

Рекомендації:

- чітко розділити development і production Compose-конфігурації;
- у development використовувати окремий volume для `/var/www/vendor`;
- у production не монтувати source-код із host.

### Права на файли у Makefile

`make bootstrap` використовує `sudo chmod` і `sudo chown`:

- `Makefile:43`
- `Makefile:48`
- `Makefile:49`

Це може:

- створювати файли з незручним власником на host;
- робити bootstrap залежним від `sudo`;
- працювати по-різному на Linux і macOS;
- приховувати неправильну модель прав у контейнері.

Рекомендація: узгодити UID/GID контейнера з host-користувачем або використовувати named volumes для runtime/vendor.

### Індекси бази даних

Початкова міграція створювала окремі індекси для `user_id`, `status`,
`priority` і `due_date`. Міграція
`m260725_000002_optimize_task_list_indexes` замінює їх складеними індексами
під tenant-scoped запити API:

```text
(user_id, created_at, id)
(user_id, status, created_at, id)
(user_id, priority, created_at, id)
(user_id, due_date)
```

Кількість secondary indexes не збільшилася. Порядок колонок перевіряється
MySQL integration-тестом. Після появи production-метрик набір потрібно
повторно перевірити через slow query log та `EXPLAIN ANALYZE`.

### Спостережуваність

Наразі логуються тільки warning та error у файл.

Для production варто додати:

- структуровані JSON-логи;
- request/correlation ID;
- duration і HTTP status кожного запиту;
- метрики auth-service, DB, Redis і rate limiting;
- health endpoint;
- readiness endpoint із перевіркою критичних залежностей;
- централізований збір логів;
- error tracking.

### API-документація

README містить приклади curl, але немає формальної специфікації API.

Рекомендація: додати OpenAPI 3.x із:

- endpoint-ами;
- схемами запитів і відповідей;
- security scheme для Bearer token;
- кодами `400`, `401`, `404`, `422`, `429`, `500`, `503`;
- правилами pagination і filtering.

## Тести

### Поточний стан

Feature-тести добре покривають:

- відсутність авторизації;
- CRUD;
- ізоляцію завдань користувачів;
- основні фільтри;
- pagination;
- базову валідацію.

Синтаксична перевірка всіх PHP-файлів пройшла без помилок.

PHPUnit під час рев’ю не запускався, оскільки директорія `www/vendor` і виконуваний файл `www/vendor/bin/phpunit` були відсутні.

### Тест invalid token перевіряє інший сценарій

У `www/tests/Feature/TaskTest.php:22` тест вручну встановлює:

```php
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid-token-xyz';
```

Але helper у `www/tests/TestCase.php:100` одразу переписує заголовок порожнім значенням, оскільки викликається `get(..., auth: false)`.

У результаті тест повторно перевіряє відсутній токен, а не невалідний токен.

Рекомендація: дозволити helper-у приймати конкретний token/header або додати окремий метод для authenticated request із довільним токеном.

### SQLite не повністю відтворює production

Тести використовують SQLite in-memory, а production використовує MySQL 8 та `ENUM`.

Такі тести не знаходять:

- MySQL-специфічні проблеми міграцій;
- відмінності типів і strict mode;
- особливості `ENUM`;
- поведінку collation;
- реальні query plans та індекси.

Рекомендація: залишити SQLite-тести швидким рівнем, але додати окремий integration suite з MySQL у CI.

### Тести, яких бракує

Варто додати перевірки:

- минулої `due_date` при update;
- стабільного сортування;
- некоректних query-параметрів;
- порожнього та malformed JSON;
- надто довгого title;
- Unicode-пошуку;
- одночасних запитів до rate limiter;
- `Retry-After` при `429`;
- timeout і недоступності auth-service;
- недоступності Redis;
- помилки видалення у БД;
- приховування деталей exception у production;
- коректного завантаження `.env`;
- реальних MySQL-міграцій;
- HTTP method `PATCH`, якщо часткове оновлення планується підтримувати.

## CI/CD і контроль якості

У репозиторії варто налаштувати CI pipeline, який виконує:

1. `composer validate`.
2. `composer install`.
3. PHP syntax check.
4. PHP CS Fixer у режимі `--dry-run`.
5. PHPUnit.
6. MySQL integration tests.
7. Static analysis через PHPStan або Psalm.
8. `composer audit`.
9. Перевірку Docker build.
10. Container/dependency security scan.

Також бажано:

- додати coverage threshold;
- заборонити merge при падінні перевірок;
- автоматично перевіряти актуальність OpenAPI;
- використовувати production-подібне оточення на integration-етапі.

## Рекомендований порядок робіт

### P0 — перед production

1. Узгодити `.env`, Docker Compose та README.
2. Виправити завантаження `YII_ENV` і `YII_DEBUG`.
3. Приховати внутрішні повідомлення помилок `500`.
4. Налаштувати доступ task-service до auth-service.
5. Замінити неатомарний rate limiter.

### P1 — важлива коректність API

1. Валідувати `due_date` під час update.
2. Додати стабільне сортування.
3. Перевіряти результат видалення.
4. Валідувати query-параметри.
5. Не розкривати існування чужих завдань.
6. Розрізняти invalid token і недоступність auth-service.

### P2 — якість та експлуатація

1. Виправити тест invalid token.
2. Додати MySQL integration suite.
3. Налаштувати CI.
4. Додати PHPStan/Psalm та `composer audit`.
5. Додати health/readiness endpoints.
6. Додати структуровані логи й метрики.
7. Підготувати OpenAPI-специфікацію.
8. Розділити development і production Docker-конфігурації.

## Підсумок

Архітектура достатня для невеликого CRUD-мікросервісу, а код легко читати та розширювати. Основні ризики зараз знаходяться не в CRUD-логіці, а на межах системи: конфігурація Docker, взаємодія з auth-service, rate limiting, обробка production-помилок і відмінності між тестовою та production базами.

Після виправлення пунктів P0 і P1 сервіс стане значно передбачуванішим, безпечнішим і придатнішим до production-експлуатації.

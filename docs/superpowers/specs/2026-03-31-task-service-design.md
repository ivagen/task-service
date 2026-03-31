# Task Service — Design Spec
Date: 2026-03-31

## Overview

Yii2 REST мікросервіс для управління завданнями. Авторизація делегується існуючому Laravel Passport сервісу через виклик `/api/user` з Bearer токеном.

---

## Tech Stack

- PHP 8.5+, Yii2 yii2-app-basic
- MySQL 8.0
- Redis (кеш для токенів і rate limiting)
- Guzzle HTTP client
- vlucas/phpdotenv
- Docker + Docker Compose + Nginx (та сама структура що й auth_service)

---

## Project Structure

```
task-service/
├── www/                          # Yii2 app
│   ├── behaviors/
│   │   └── PassportAuthBehavior.php
│   ├── components/
│   │   ├── PassportAuth.php
│   │   └── User.php
│   ├── config/
│   │   ├── web.php
│   │   ├── db.php
│   │   ├── params.php
│   │   └── bootstrap.php
│   ├── controllers/
│   │   └── TaskController.php
│   ├── migrations/
│   │   └── m_xxxxxx_create_tasks_table.php
│   ├── models/
│   │   ├── Task.php
│   │   └── TaskSearch.php
│   ├── web/
│   │   └── index.php
│   ├── .env.example
│   └── composer.json
├── docker/
│   └── nginx/
│       └── default.conf
├── Dockerfile
├── docker-compose.yml
└── README.md
```

---

## Database Schema

Table: `tasks`

| Column | Type | Constraints |
|--------|------|-------------|
| id | BIGINT UNSIGNED | PK, AUTO_INCREMENT |
| user_id | BIGINT UNSIGNED | NOT NULL |
| title | VARCHAR(255) | NOT NULL |
| description | TEXT | NULL |
| status | ENUM('todo','in_progress','done') | DEFAULT 'todo' |
| priority | TINYINT | DEFAULT 1 (1=low, 2=medium, 3=high) |
| due_date | DATE | NULL |
| created_at | INT | NOT NULL |
| updated_at | INT | NOT NULL |

---

## Authorization Flow

```
Request → PassportAuthBehavior.beforeAction()
           → PassportAuth::validate($token)
               → check cache key "passport_token_{md5($token)}"
               → if miss: GET AUTH_SERVICE_URL/api/user
                          Authorization: Bearer {token}
                          Guzzle timeout: 3s, connect_timeout: 2s
               → if 200: cache user data for 60s, return array
               → if non-200 or GuzzleException: return false
           → if false: respond JSON 401, stop action
           → Yii::$app->user->setIdentity(new User($data))
           → action proceeds
```

**Key rules:**
- Guzzle exceptions are caught — never crash the app, always return 401
- Token cached by `md5($token)` key for 60 seconds
- `user_id` is ALWAYS taken from the validated token, never from request body

---

## Rate Limiting

Implemented inside `PassportAuthBehavior`, after successful token validation:

- Cache key: `rate_limit_{user_id}`
- Increment counter per request; set TTL 60s on first request
- If counter > 60: respond JSON 429, stop action

---

## API Endpoints

Base URL: `/api/v1`

| Method | Path | Description |
|--------|------|-------------|
| GET | `/tasks` | List tasks (auth user only) with filters + pagination |
| POST | `/tasks` | Create task |
| GET | `/tasks/{id}` | Get single task |
| PUT | `/tasks/{id}` | Update task |
| DELETE | `/tasks/{id}` | Delete task |

### Filters for GET /tasks

| Param | Values |
|-------|--------|
| status | todo, in_progress, done |
| priority | 1, 2, 3 |
| due_date_from | Y-m-d |
| due_date_to | Y-m-d |
| search | searches title + description |
| page | default 1 |
| per_page | default 20, max 100 |

### Ownership Check

Private method `findOwnTask($id)` in controller:
- not found → 404
- found but `user_id != auth user id` → 403
- found and owned → return model

---

## Response Formats

**Single task:**
```json
{
  "success": true,
  "data": { "id": 1, "user_id": 5, "title": "...", "description": "...", "status": "todo", "priority": 1, "due_date": "2026-04-10", "created_at": 1743379200, "updated_at": 1743379200 }
}
```

**List:**
```json
{
  "success": true,
  "data": [...],
  "meta": { "total": 50, "page": 1, "per_page": 20, "total_pages": 3 }
}
```

**Error:**
```json
{
  "success": false,
  "error": { "code": 401, "message": "Unauthorized" }
}
```

Error handler overridden in `web.php` to always return this JSON format.

---

## Model Validation Rules (Task)

| Field | Rule |
|-------|------|
| title | required, string, max 255 |
| description | optional, string |
| status | in: todo, in_progress, done |
| priority | integer, in: 1, 2, 3 |
| due_date | optional, date Y-m-d, must be today or future (on create) |
| user_id | never accepted from request body |

---

## Docker / Infrastructure

Same pattern as `auth_service`:
- `Dockerfile`: php:8.5-fpm, installs pdo_mysql, mbstring, redis extension
- `docker-compose.yml`: services app, nginx, mysql, redis
- Nginx port: 8001 (to avoid conflict with auth_service on 8000)
- `docker/nginx/default.conf`: root `/var/www/web`, fastcgi to app:9000

---

## Environment Variables (.env.example)

```
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=task_service
DB_USER=root
DB_PASSWORD=
AUTH_SERVICE_URL=http://localhost:8000
CACHE_DRIVER=redis
```
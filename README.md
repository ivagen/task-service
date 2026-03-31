# Task Service

Yii2 REST мікросервіс для управління завданнями. Авторизація через Laravel Passport.

## Вимоги

- Docker та Docker Compose
- Запущений auth_service (Laravel Passport) на порту 8000

## Налаштування

### 1. Клонуй репозиторій та перейди в директорію

```bash
git clone <repo-url> task-service
cd task-service
```

### 2. Створи .env файл

```bash
cp www/.env.example www/.env
```

Відредагуй `www/.env`:

```
DB_HOST=mysql
DB_PORT=3306
DB_NAME=task_service
DB_USER=task_service
DB_PASSWORD=secret
AUTH_SERVICE_URL=http://host.docker.internal:8000
CACHE_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

### 3. Запусти контейнери

```bash
docker compose up -d --build
```

### 4. Виконай міграції

```bash
docker compose exec app php yii migrate --interactive=0
```

### 5. Сервіс доступний на http://localhost:8002

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
    "message": "Unauthorized"
  }
}
```

---

## Зупинити сервіс

```bash
docker compose down
```
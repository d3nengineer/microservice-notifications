# Notification Microservice

Laravel microservice for SMS and email notifications.

The service accepts notification batches, stores notifications in PostgreSQL, protects duplicate requests with idempotency, publishes delivery work through an outbox flow, and processes delivery attempts with retry-aware gateway handling.

## Stack

- PHP 8.5
- Laravel 13
- PostgreSQL
- Redis
- Kafka
- Docker Compose

## Quick Start

Create a local environment file:

```bash
cp .env.example .env
php artisan key:generate
```

For Docker, make sure `.env` points to compose service names:

```dotenv
DB_HOST=postgres
DB_USERNAME=notification
DB_PASSWORD=notification
CACHE_STORE=redis
REDIS_HOST=redis
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
NOTIFICATION_KAFKA_PRODUCER=fake
NOTIFICATION_KAFKA_CONSUMER=fake
NOTIFICATION_EMAIL_GATEWAY=fake
NOTIFICATION_SMS_GATEWAY=fake
```

Start the runtime:

```bash
docker compose up
```

Run migrations:

```bash
docker compose --profile ops run --rm migrate
```

The API is available at:

```text
http://localhost:8000
```

## Services

| Service | Purpose |
| --- | --- |
| `app` | Laravel HTTP API |
| `outbox-publisher` | Runs `php artisan outbox:publish --once` in a loop |
| `notification-consumer` | Runs `php artisan notifications:consume --once` in a loop |
| `postgres` | Application database |
| `redis` | Locks, rate limits, and cache |
| `kafka` | Local Kafka broker |
| `kafka-ui` | Optional Kafka UI, enabled with `--profile tools` |
| `mailpit` | Optional email inspection UI, enabled with `--profile mail` |

Optional tools:

```bash
docker compose --profile tools --profile mail up --build
```

Kafka UI:

```text
http://localhost:8080
```

Mailpit:

```text
http://localhost:8025
```

## Useful Commands

Run tests locally:

```bash
php artisan test --compact
```

Format PHP code:

```bash
vendor/bin/pint --dirty --format agent
```

Validate the compose file:

```bash
docker compose config
```

Run a one-off Artisan command inside the app image:

```bash
docker compose run --rm app php artisan route:list --except-vendor
```

## API

The current API is versioned under `/api/v1`.

Key endpoints:

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/notification-batches` | Create a bulk notification batch |
| `GET` | `/api/v1/subscribers/{recipientId}/notifications` | List subscriber notification history |

## Notes

The Docker runtime currently uses the existing `fake` Kafka adapters for application-level producer and consumer wiring. Kafka infrastructure is included for local runtime parity, but real Kafka producer and consumer adapters are intentionally separate work.

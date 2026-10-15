# condohub-reservas

Reservas de áreas comunes de CondoHub y orquestador de la saga. Laravel 12 · PHP 8.2+ · PostgreSQL · Clean.

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate        # necesita el compose de infra/
php artisan serve --port=8082
vendor/bin/pest
```

Arquitectura, reglas, estado, roles por endpoint y variables: `CLAUDE.md`.
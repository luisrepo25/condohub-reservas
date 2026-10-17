# CLAUDE.md — condohub-reservas

Microservicio **Reservas** de CondoHub: contexto delimitado **Reservas** (áreas comunes, reservas, calendario, garantías) y **orquestador de la saga** de reserva. Responsable: **Erick**.

Este repo es independiente. Si está dentro del espacio de trabajo `condohub`, las reglas completas están en `../../docs/negocio/reglas-de-negocio.md`, los contratos en `../../contracts/openapi/reservas.yaml` y `../../contracts/events/`.

## Stack

PHP 8.2+ · Laravel 12 (ver `docs/adr/0001-laravel-12.md`) · PostgreSQL 16 (esquema `reservas`, base propia) · php-amqplib (RabbitMQ) · validación JWT de Keycloak (firebase/php-jwt con JWKS) · Pest · PHPStan nivel 8 · Laravel Pint.

## Arquitectura: Clean

Laravel queda en el borde. El dominio no conoce Eloquent, facades ni helpers de Laravel.

```
src/
├── Domain/                 # PURO PHP
│   ├── Model/              # Reserva, AreaComun, SagaReserva (agregados)
│   ├── ValueObject/        # BloqueHorario, Dinero, IdempotencyKey, EstadoReserva
│   ├── Event/              # ReservaSolicitada, ReservaConfirmada, ReservaFallida, ReservaCancelada
│   ├── Service/            # VerificadorDisponibilidad
│   └── Port/               # interfaces: ReservaRepository, SagaRepository, FinanzasGateway, EventPublisher, Clock
├── Application/
│   ├── UseCase/            # SolicitarReserva, CancelarReserva, FinalizarReserva
│   └── Saga/               # OrquestadorSagaReserva: pasos, reintentos, compensaciones
└── Infrastructure/
    ├── Http/               # controllers Laravel finos + FormRequests → llaman UseCase
    ├── Persistence/        # modelos Eloquent + repositorios que implementan los Port
    ├── Messaging/          # Outbox, publicador y consumidores RabbitMQ
    └── Gateway/            # FinanzasGatewayGraphQL (consulta morosidad a Gestión)
app/                        # solo bootstrap de Laravel (providers que conectan Port → Infrastructure)
```

Regla: `src/Domain` y `src/Application` no pueden usar `Illuminate\*` (lo verifica una regla de PHPStan/deptrac; no la desactives).

## Reglas de negocio que implementa

| Código | Resumen |
|---|---|
| R1 | Áreas: salón de eventos (aforo 80), piscina (20), churrasquera ×2 (15 c/u) |
| R2 | 08:00–23:00, bloques ≥ 2 h, sin solapamiento (restricción `EXCLUDE USING gist` en PostgreSQL además del dominio) |
| R3 | Anticipación 48 h a 60 días; máximo 2 reservas activas por unidad |
| R4 | Unidad con ≥ 2 expensas vencidas no reserva: consultar a Gestión **antes** de iniciar la saga |
| R5 | Salón: costo de uso + garantía; demás áreas: solo costo de uso (puede ser 0) |
| R6 | Garantía devuelta cuando el `ADMIN` marca `FINALIZADA` sin daños |
| R7 | Cancelación ≥ 48 h antes: se anulan cargos; después: se cobra el uso y se devuelve la garantía |
| R8 | `SOLICITADA → CONFIRMADA → FINALIZADA`, `SOLICITADA → FALLIDA`, `CONFIRMADA → CANCELADA` |
| S1–S5 | Saga orquestada aquí (ver abajo) |
| O5 | Reserva editable con `version`; versión vieja → 409 |
| O6 | No se crean reservas offline |

## Saga de reserva

| Paso | Destino | Comando / evento esperado | Compensación |
|---|---|---|---|
| 1 | local | bloquear horario, `SOLICITADA` | liberar, `FALLIDA` |
| 2 | Gestión | `reservas.AplicarCargosReserva` → `finanzas.CargoAplicado` | `reservas.AnularCargosReserva` |
| 3 | Acceso | `reservas.EmitirPasesReserva` → `acceso.PaseEmitido` | `reservas.RevocarPasesReserva` |
| 4 | Notificaciones | `reservas.ReservaConfirmada` (n8n la toma) | — |

- Estado en tabla `saga_instancia` (paso actual, estado, intentos, timestamps). Al arrancar, retoma sagas no terminadas.
- Cada paso: 3 reintentos con espera exponencial y timeout; luego compensar en orden inverso.
- Compensaciones: reintento indefinido con backoff; si no se logran, `REQUIERE_REVISION` (visible en la consola admin).
- Cambio de estado + mensaje en la misma transacción (tabla `outbox`). Consumidores idempotentes (tabla `evento_procesado`).
- Toda la saga lleva `sagaId` en los eventos y en los logs.

## Comandos

```bash
composer install
php artisan migrate
php artisan serve --port=8082
php artisan saga:consumir          # worker de mensajes de la saga
php artisan outbox:publicar        # publicador del outbox (también programado)
./vendor/bin/pest                  # tests
./vendor/bin/phpstan analyse
./vendor/bin/pint
```

## Convenciones locales

- Tests de dominio sin base de datos: `it('R3 rechaza una reserva con menos de 48 h de anticipación')`.
- Tests de saga con dobles de `EventPublisher`: un test por cada camino de compensación (falla en paso 2, falla en paso 3).
- Base de datos en SQL nativo (`database/sql/`), ejecutado por una migración Laravel: el Schema Builder no soporta `EXCLUDE` (R2) ni triggers (R8). Cambios = archivo nuevo `00N_...sql` + migración nueva. Pruebas en `tests/db/pruebas_reglas_bd.sql`.
- Controllers sin lógica: validan forma (FormRequest), llaman un UseCase y mapean el resultado.

## Estado y seguridad (esqueleto, 14/10/2026)

Los endpoints existen y ya están protegidos por rol; su lógica no: responden `501` (`NO_IMPLEMENTADO`), a quitar al implementar cada uno. Laravel 12 (ADR 0001).

- Roles (propuesta derivada de las reglas y escrita en `contracts/openapi/reservas.yaml`, a confirmar con Erick): `GET /api/salud` y `GET /api/areas` cualquiera · `POST/GET /api/reservas` y `POST /api/reservas/{id}/cancelar`: RESIDENTE, ADMIN · `POST /api/reservas/{id}/finalizar` y `GET /api/sagas/requieren-revision`: ADMIN.
- Rutas con middleware `jwt` / `jwt:RESIDENTE,ADMIN` en `routes/api.php`; JWT en `src/Infrastructure/Security/KeycloakJwtVerificador.php` (JWKS en caché); middleware en `src/Infrastructure/Http/Middleware/ProtegerConJwt.php`; errores problem+json en `Http/Problema.php`.
- El esquema SQL y sus pruebas (`tests/db/pruebas_reglas_bd.sql`) ya existían y pasan con la migración. Los tests de arquitectura impiden `Illuminate` en `Domain` y `Application`.
- Comandos: `composer install`, `cp .env.example .env && php artisan key:generate`, `php artisan migrate`, `vendor/bin/pest` (31 tests, incluido el de contrato con `reservas.yaml`), `vendor/bin/phpstan analyse` (nivel 8), `vendor/bin/pint --test`. En XAMPP los tests necesitan `openssl.cnf` (el helper lo busca junto al binario de PHP). Con el 5432 ocupado: `DB_PORT=5433`.
- Contenedor y CI (16/10/2026): `Dockerfile` en la raíz del repo; `infra/docker-compose.apps.yml` lo levanta junto al resto; `.github/workflows/ci.yml` corre las pruebas y publica la imagen en GHCR desde `main`.

## Código funcional protegido (obligatorio)

Código en `main` con tests en verde **no se reescribe, refactoriza, renombra ni reformatea** si la tarea no lo pide explícitamente. Lo nuevo se agrega extendiendo (clases, métodos o archivos nuevos). Si es inevitable tocar código funcional, **detente antes de editar**, explica qué archivo/líneas y por qué, y espera confirmación; luego haz el mínimo diff. Nunca edites tests existentes para que pase código nuevo. Corre los tests antes y después: todo lo que pasaba debe seguir pasando. En el resumen, lista cada archivo existente que modificaste.

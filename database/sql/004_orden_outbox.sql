-- =====================================================================
-- CondoHub · Reservas · 004: orden estable del outbox (S4)
-- occurred_at tiene precisión de segundos: dos mensajes del mismo segundo se desempataban por su id (aleatorio) y
-- podían salir en otro orden. La secuencia guarda el orden en que se insertaron.
-- Se ejecuta desde database/migrations/2026_10_29_000004_orden_outbox.php
-- =====================================================================

ALTER TABLE outbox ADD COLUMN secuencia BIGSERIAL;

DROP INDEX IF EXISTS ix_outbox_pendiente;
CREATE INDEX ix_outbox_pendiente ON outbox (occurred_at, secuencia) WHERE published_at IS NULL;

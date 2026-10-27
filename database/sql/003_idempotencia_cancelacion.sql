-- =====================================================================
-- CondoHub · Reservas · 003: idempotencia de la cancelación (O1–O3, R7)
-- `POST /api/reservas/{id}/cancelar` lleva Idempotency-Key: repetirla devuelve la misma reserva cancelada en vez de
-- un 409. La clave de la cancelación se guarda en la propia reserva (una reserva se cancela una sola vez).
-- Se ejecuta desde database/migrations/2026_10_27_000003_idempotencia_cancelacion.php
-- =====================================================================

ALTER TABLE reserva ADD COLUMN cancelacion_idempotency_key UUID UNIQUE;

ALTER TABLE reserva ADD CONSTRAINT ck_reserva_clave_cancelacion
    CHECK (cancelacion_idempotency_key IS NULL OR estado = 'CANCELADA');

-- =====================================================================
-- CondoHub · Reservas (contexto Reservas + orquestador de saga) · PostgreSQL 16
-- 001: esquema base. Reglas: R1–R8, S1–S5, O5 (docs/negocio/reglas-de-negocio.md)
-- Base exclusiva de este servicio. unidad_id es una referencia por id al
-- contexto Finanzas (sin FK: otra base).
-- Se ejecuta desde database/migrations/2026_10_15_000001_esquema_reservas.php
-- =====================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;
CREATE EXTENSION IF NOT EXISTS btree_gist;   -- EXCLUDE con igualdad + rango (R2)

CREATE TYPE tipo_area      AS ENUM ('SALON_EVENTOS', 'PISCINA', 'CHURRASQUERA');
CREATE TYPE estado_reserva AS ENUM ('SOLICITADA', 'CONFIRMADA', 'FINALIZADA', 'FALLIDA', 'CANCELADA');
CREATE TYPE estado_saga    AS ENUM ('EN_CURSO', 'COMPLETADA', 'COMPENSANDO', 'COMPENSADA', 'REQUIERE_REVISION');
CREATE TYPE estado_paso    AS ENUM ('PENDIENTE', 'EN_CURSO', 'OK', 'FALLIDO', 'COMPENSADO', 'COMPENSACION_FALLIDA', 'OMITIDO');

-- ---------------------------------------------------------------------
-- Parámetros (R2, R3, R7)
-- ---------------------------------------------------------------------
CREATE TABLE parametro (
    clave          VARCHAR(60)  PRIMARY KEY,
    valor          VARCHAR(200) NOT NULL,
    descripcion    VARCHAR(300) NOT NULL,
    actualizado_en TIMESTAMPTZ  NOT NULL DEFAULT now()
);
INSERT INTO parametro (clave, valor, descripcion) VALUES
  ('reserva.anticipacion_min_horas', '48', 'R3: anticipación mínima'),
  ('reserva.anticipacion_max_dias',  '60', 'R3: anticipación máxima'),
  ('reserva.max_activas_por_unidad', '2',  'R3: reservas SOLICITADA o CONFIRMADA por unidad'),
  ('reserva.cancelacion_sin_costo_horas', '48', 'R7: cancelación sin costo hasta estas horas antes'),
  ('saga.reintentos_por_paso',       '3',  'S2: reintentos antes de compensar'),
  ('saga.timeout_paso_segundos',     '30', 'S2: tiempo de espera de la respuesta de cada paso');

-- ---------------------------------------------------------------------
-- Áreas comunes (agregado AreaComun · R1, R5)
-- ---------------------------------------------------------------------
CREATE TABLE area_comun (
    id                  UUID          PRIMARY KEY DEFAULT gen_random_uuid(),
    codigo              VARCHAR(20)   NOT NULL UNIQUE,
    nombre              VARCHAR(80)   NOT NULL,
    tipo                tipo_area     NOT NULL,
    aforo               INTEGER       NOT NULL CHECK (aforo > 0),
    hora_apertura       TIME          NOT NULL DEFAULT '08:00',
    hora_cierre         TIME          NOT NULL DEFAULT '23:00',
    bloque_minimo_horas INTEGER       NOT NULL DEFAULT 2 CHECK (bloque_minimo_horas > 0),
    costo_uso           NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (costo_uso >= 0),
    garantia            NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (garantia >= 0),
    activa              BOOLEAN       NOT NULL DEFAULT true,
    CHECK (hora_cierre > hora_apertura),
    CHECK (tipo = 'SALON_EVENTOS' OR garantia = 0)          -- R5: solo el salón tiene garantía
);

-- ---------------------------------------------------------------------
-- Reservas (agregado Reserva · R2, R3, R6–R8, O5)
-- ---------------------------------------------------------------------
CREATE TABLE reserva (
    id                UUID           PRIMARY KEY DEFAULT gen_random_uuid(),
    area_id           UUID           NOT NULL REFERENCES area_comun(id),
    unidad_id         UUID           NOT NULL,                 -- id del contexto Finanzas
    solicitada_por    VARCHAR(64)    NOT NULL,                 -- keycloak sub del RESIDENTE
    inicio            TIMESTAMPTZ    NOT NULL,
    fin               TIMESTAMPTZ    NOT NULL,
    horario           TSTZRANGE      GENERATED ALWAYS AS (tstzrange(inicio, fin, '[)')) STORED,
    cantidad_invitados INTEGER       NOT NULL DEFAULT 0 CHECK (cantidad_invitados >= 0),
    estado            estado_reserva NOT NULL DEFAULT 'SOLICITADA',
    costo_uso         NUMERIC(12,2)  NOT NULL CHECK (costo_uso >= 0),   -- copiado del área al reservar
    garantia          NUMERIC(12,2)  NOT NULL CHECK (garantia >= 0),
    con_danios        BOOLEAN,                                  -- R6: lo marca el ADMIN al finalizar
    motivo            VARCHAR(300),                             -- de FALLIDA o CANCELADA
    idempotency_key   UUID           NOT NULL UNIQUE,           -- O1: doble clic o reintento no duplica
    version           INTEGER        NOT NULL DEFAULT 0,        -- O5: bloqueo optimista
    creada_en         TIMESTAMPTZ    NOT NULL DEFAULT now(),
    actualizada_en    TIMESTAMPTZ    NOT NULL DEFAULT now(),
    CHECK (fin > inicio),
    CHECK (fin - inicio >= interval '1 hour'),                  -- mínimo absoluto; el del área lo valida el dominio
    CHECK (estado NOT IN ('FALLIDA', 'CANCELADA') OR motivo IS NOT NULL),
    CHECK (estado = 'FINALIZADA' OR con_danios IS NULL),
    -- R2: dos reservas vivas del mismo área nunca se solapan, ni con concurrencia
    CONSTRAINT ex_reserva_sin_solape EXCLUDE USING gist (area_id WITH =, horario WITH &&)
        WHERE (estado IN ('SOLICITADA', 'CONFIRMADA'))
);
CREATE INDEX ix_reserva_unidad_activa ON reserva (unidad_id) WHERE estado IN ('SOLICITADA', 'CONFIRMADA');
CREATE INDEX ix_reserva_area_inicio ON reserva (area_id, inicio);

-- R8: solo transiciones válidas
CREATE FUNCTION fn_reserva_transicion() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.estado <> OLD.estado AND NOT (
           (OLD.estado = 'SOLICITADA' AND NEW.estado IN ('CONFIRMADA', 'FALLIDA'))
        OR (OLD.estado = 'CONFIRMADA' AND NEW.estado IN ('FINALIZADA', 'CANCELADA'))
    ) THEN
        RAISE EXCEPTION 'R8: transición inválida % → % (reserva %)', OLD.estado, NEW.estado, OLD.id
              USING ERRCODE = 'P0001';
    END IF;
    IF NEW.area_id <> OLD.area_id OR NEW.unidad_id <> OLD.unidad_id THEN
        RAISE EXCEPTION 'R8: no se cambia el área ni la unidad de una reserva' USING ERRCODE = 'P0001';
    END IF;
    NEW.actualizada_en := now();
    RETURN NEW;
END $$;
CREATE TRIGGER tg_reserva_transicion BEFORE UPDATE ON reserva
    FOR EACH ROW EXECUTE FUNCTION fn_reserva_transicion();

CREATE TABLE invitado_reserva (
    id         UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    reserva_id UUID         NOT NULL REFERENCES reserva(id) ON DELETE CASCADE,
    nombre     VARCHAR(120) NOT NULL,
    documento  VARCHAR(30),
    UNIQUE (reserva_id, documento)
);

-- ---------------------------------------------------------------------
-- Saga orquestada (agregado SagaReserva · S1–S3)
-- ---------------------------------------------------------------------
CREATE TABLE saga_instancia (
    id                UUID        PRIMARY KEY DEFAULT gen_random_uuid(),   -- = sagaId de los eventos
    tipo              VARCHAR(40) NOT NULL DEFAULT 'RESERVA_AREA',
    reserva_id        UUID        NOT NULL UNIQUE REFERENCES reserva(id),
    estado            estado_saga NOT NULL DEFAULT 'EN_CURSO',
    paso_actual       SMALLINT    NOT NULL DEFAULT 1,
    proximo_reintento TIMESTAMPTZ,                       -- S2: backoff; el worker retoma desde aquí
    ultimo_error      TEXT,
    contexto          JSONB       NOT NULL DEFAULT '{}', -- ids devueltos por cada paso (cargos, pases)
    creada_en         TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizada_en    TIMESTAMPTZ NOT NULL DEFAULT now(),
    terminada_en      TIMESTAMPTZ,
    CHECK ((estado IN ('COMPLETADA', 'COMPENSADA')) = (terminada_en IS NOT NULL))
);
-- S1: al reiniciar, el worker busca sagas no terminadas
CREATE INDEX ix_saga_pendiente ON saga_instancia (proximo_reintento) WHERE estado IN ('EN_CURSO', 'COMPENSANDO');
CREATE INDEX ix_saga_revision ON saga_instancia (actualizada_en) WHERE estado = 'REQUIERE_REVISION';

CREATE TABLE saga_paso (
    saga_id          UUID        NOT NULL REFERENCES saga_instancia(id),
    numero           SMALLINT    NOT NULL CHECK (numero BETWEEN 1 AND 4),
    nombre           VARCHAR(40) NOT NULL,     -- BLOQUEAR_HORARIO, APLICAR_CARGOS, EMITIR_PASES, NOTIFICAR
    estado           estado_paso NOT NULL DEFAULT 'PENDIENTE',
    intentos         SMALLINT    NOT NULL DEFAULT 0 CHECK (intentos >= 0),
    intentos_compensacion SMALLINT NOT NULL DEFAULT 0,
    comando_event_id UUID,                     -- eventId del comando enviado (correlación)
    respuesta        JSONB,
    actualizado_en   TIMESTAMPTZ NOT NULL DEFAULT now(),
    PRIMARY KEY (saga_id, numero)
);

-- ---------------------------------------------------------------------
-- Mensajería confiable (S4, S5)
-- ---------------------------------------------------------------------
CREATE TABLE outbox (
    id             UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
    aggregate_type VARCHAR(40)  NOT NULL,
    aggregate_id   VARCHAR(80)  NOT NULL,
    event_type     VARCHAR(60)  NOT NULL,
    routing_key    VARCHAR(100) NOT NULL,     -- ej. reservas.AplicarCargosReserva
    saga_id        UUID,
    payload        JSONB        NOT NULL,
    occurred_at    TIMESTAMPTZ  NOT NULL DEFAULT now(),
    published_at   TIMESTAMPTZ,
    intentos       INTEGER      NOT NULL DEFAULT 0
);
CREATE INDEX ix_outbox_pendiente ON outbox (occurred_at) WHERE published_at IS NULL;

CREATE TABLE evento_procesado (
    event_id     UUID        PRIMARY KEY,
    event_type   VARCHAR(60) NOT NULL,
    procesado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Datos de referencia (R1): forman parte de la regla, no son demo
-- ---------------------------------------------------------------------
INSERT INTO area_comun (codigo, nombre, tipo, aforo, costo_uso, garantia) VALUES
  ('SALON',   'Salón de eventos', 'SALON_EVENTOS', 80, 500.00, 300.00),
  ('PISCINA', 'Piscina',          'PISCINA',       20,   0.00,   0.00),
  ('CHURR-1', 'Churrasquera 1',   'CHURRASQUERA',  15,  50.00,   0.00),
  ('CHURR-2', 'Churrasquera 2',   'CHURRASQUERA',  15,  50.00,   0.00);

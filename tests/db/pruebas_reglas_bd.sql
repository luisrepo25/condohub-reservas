-- Pruebas de las reglas que protege la base de Reservas.
--   psql -d reservas -v ON_ERROR_STOP=1 -f tests/db/pruebas_reglas_bd.sql

CREATE OR REPLACE FUNCTION pg_temp.espera_error(p_regla TEXT, p_sql TEXT) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'OK %: %', p_regla, SQLERRM;
        RETURN;
    END;
    RAISE EXCEPTION 'FALLA %: la operación debió rechazarse', p_regla;
END $$;

BEGIN;
INSERT INTO reserva (id, area_id, unidad_id, solicitada_por, inicio, fin, costo_uso, garantia, idempotency_key)
SELECT '11111111-1111-1111-1111-111111111111', id, gen_random_uuid(), 'sub-demo',
       '2026-11-20 18:00-04', '2026-11-20 23:00-04', costo_uso, garantia, gen_random_uuid()
FROM area_comun WHERE codigo = 'SALON';

SELECT pg_temp.espera_error('R2 solape en el mismo área',
  $q$INSERT INTO reserva (area_id, unidad_id, solicitada_por, inicio, fin, costo_uso, garantia, idempotency_key)
     SELECT id, gen_random_uuid(), 'otro', '2026-11-20 20:00-04', '2026-11-20 23:00-04', 0, 0, gen_random_uuid()
     FROM area_comun WHERE codigo = 'SALON'$q$);
SELECT pg_temp.espera_error('R8 SOLICITADA → FINALIZADA',
  $q$UPDATE reserva SET estado = 'FINALIZADA' WHERE id = '11111111-1111-1111-1111-111111111111'$q$);
SELECT pg_temp.espera_error('R8 FALLIDA sin motivo',
  $q$UPDATE reserva SET estado = 'FALLIDA' WHERE id = '11111111-1111-1111-1111-111111111111'$q$);
SELECT pg_temp.espera_error('R5 garantía fuera del salón',
  $q$INSERT INTO area_comun (codigo, nombre, tipo, aforo, garantia) VALUES ('X', 'X', 'PISCINA', 5, 10)$q$);
SELECT pg_temp.espera_error('O1 idempotency_key repetida',
  $q$INSERT INTO reserva (area_id, unidad_id, solicitada_por, inicio, fin, costo_uso, garantia, idempotency_key)
     SELECT area_id, unidad_id, 'x', '2026-12-01 10:00-04', '2026-12-01 12:00-04', 0, 0, idempotency_key
     FROM reserva LIMIT 1$q$);

-- Una reserva FALLIDA libera el horario (compensación del paso 1)
UPDATE reserva SET estado = 'FALLIDA', motivo = 'Prueba de compensación' WHERE id = '11111111-1111-1111-1111-111111111111';
INSERT INTO reserva (area_id, unidad_id, solicitada_por, inicio, fin, costo_uso, garantia, idempotency_key)
SELECT id, gen_random_uuid(), 'otro', '2026-11-20 20:00-04', '2026-11-20 23:00-04', 0, 0, gen_random_uuid()
FROM area_comun WHERE codigo = 'SALON';
DO $$ BEGIN RAISE NOTICE 'OK S2 horario liberado tras FALLIDA'; END $$;
ROLLBACK;

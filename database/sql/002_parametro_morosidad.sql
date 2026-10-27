-- =====================================================================
-- CondoHub · Reservas · 002: parámetro de morosidad (R4)
-- Una unidad con esta cantidad o más de expensas vencidas no reserva. El dato de cuántas tiene lo da Gestión
-- (consulta antes de iniciar la saga); el límite es de Reservas y vive aquí, no en el código.
-- Se ejecuta desde database/migrations/2026_10_26_000002_parametro_morosidad.php
-- =====================================================================

INSERT INTO parametro (clave, valor, descripcion) VALUES
  ('reserva.expensas_vencidas_bloqueo', '2', 'R4: con esta cantidad o más de expensas vencidas la unidad no reserva')
ON CONFLICT (clave) DO NOTHING;

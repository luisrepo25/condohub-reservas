<?php

// Configuración propia de Reservas. Los parámetros de negocio (48 h, 60 días...) están en la tabla `parametro`.

return [
    // R2: el horario reservable (08:00–23:00) se mide en la hora local del condominio; las reservas se guardan en UTC.
    'zona_horaria' => env('CONDOMINIO_ZONA_HORARIA', 'America/La_Paz'),
];

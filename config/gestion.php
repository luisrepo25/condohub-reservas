<?php

// Conexión con Gestión (contexto Finanzas) por su API GraphQL, a través del gateway. Solo lectura (R4).
// En Docker: http://gateway:8000/gestion/graphql. Fuera de Docker: el gateway del host (GATEWAY_PORT).

return [
    'graphql_url' => env('GESTION_GRAPHQL_URL', 'http://localhost:8080/gestion/graphql'),
    // Una consulta de morosidad es rápida; si Gestión tarda más, es mejor responder 503 que dejar al usuario esperando.
    'timeout_segundos' => (int) env('GESTION_TIMEOUT_SEGUNDOS', 5),
    'reintentos' => (int) env('GESTION_REINTENTOS', 1),
];

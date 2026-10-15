<?php

$issuer = env('KEYCLOAK_ISSUER', 'http://localhost:8180/realms/condohub');

return [
    'issuer' => $issuer,
    'jwks' => env('KEYCLOAK_JWKS', $issuer.'/protocol/openid-connect/certs'),
];

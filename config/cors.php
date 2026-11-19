<?php

/**
 * Configuration CORS de l'API.
 *
 * Le backend est consommé par deux clients distincts (conformité au
 * livre, section 6.7.2 - Architecture technique) :
 *   - l'application Web développée avec React ;
 *   - l'application mobile développée avec React Native.
 *
 * Les origines autorisées peuvent être restreintes via les variables
 * d'environnement CORS_ALLOWED_ORIGINS (séparées par des virgules).
 * L'authentification reposant sur des tokens Bearer (Sanctum), les
 * cookies ne sont pas nécessaires : supports_credentials reste false.
 */

$wildcardAllowed = env('APP_ENV', 'production') === 'local';
$origins = env('CORS_ALLOWED_ORIGINS');

if ($origins === null || ($origins === '*' && !$wildcardAllowed)) {
    $origins = $wildcardAllowed ? '*' : '';
}

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins === '*'
        ? ['*']
        : array_values(array_filter(array_map('trim', explode(',', $origins)))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'Content-Disposition',
        'Content-Type',
    ],

    'max_age' => 3600,

    'supports_credentials' => false,

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Antes de este archivo, config/cors.php no existía — Laravel mergeaba
    // el default del framework en caliente (mismo valor que el resto de
    // estas claves, confirmado con `config('cors')` antes de publicar este
    // archivo), pero ese default trae max_age=0: el navegador nunca cachea
    // el resultado del preflight OPTIONS y vuelve a preguntar en cada
    // request real, duplicando los round-trips de red en todas las
    // pantallas. 3600 (1h) como piso seguro para desarrollo — evaluar subir
    // a 86400 (1 día) en producción una vez confirmado que anda bien acá.
    'max_age' => 3600,

    'supports_credentials' => false,

];

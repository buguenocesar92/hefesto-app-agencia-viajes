<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Aplicacion
    |--------------------------------------------------------------------------
    */

    'name' => env('APP_NAME', 'Agencia de Viajes'),

    'env' => env('APP_ENV', 'production'),

    'debug' => (bool) env('APP_DEBUG', false),

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Zona horaria
    |--------------------------------------------------------------------------
    |
    | Todo el producto es chileno: las fechas de vuelo y las horas de
    | anticipacion de una cancelacion se razonan en hora de Chile, no en UTC.
    | Guardar en UTC y convertir al leer es lo correcto, pero la regla de
    | anticipacion se decide sobre la hora local, asi que la referencia por
    | defecto es America/Santiago.
    */

    'timezone' => env('APP_TIMEZONE', 'America/Santiago'),

    'locale' => 'es',

    'fallback_locale' => 'es',

    'faker_locale' => 'es_CL',

    'key' => env('APP_KEY'),

    'cipher' => 'AES-256-CBC',

];
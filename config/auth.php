<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This service does not manage local users. These values are intentionally
    | empty unless an external authentication boundary is configured later.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD'),
        'passwords' => env('AUTH_PASSWORD_BROKER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards / Providers / Password Brokers
    |--------------------------------------------------------------------------
    |
    | Local session authentication, Eloquent user providers, and password reset
    | brokers are not part of this notification microservice.
    |
    */

    'guards' => [
        'web' => [],
    ],

    'providers' => [
        'users' => [],
    ],

    'passwords' => [
        'users' => [],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];

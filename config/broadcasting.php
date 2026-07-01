<?php

return [
    'default' => env('BROADCAST_CONNECTION', 'reverb'),

    'connections' => [
        'reverb' => [
    'driver' => 'reverb',
    'key' => env('REVERB_APP_KEY', 'aeroserve_key'),
    'secret' => env('REVERB_APP_SECRET', 'aeroserve_secret'),
    'app_id' => env('REVERB_APP_ID', 'aeroserve'),          // <-- Forcé à 'aeroserve' si absent
    'app_key' => env('REVERB_APP_KEY', 'aeroserve_key'),    // <-- Forcé à 'aeroserve_key' si absent
    'app_secret' => env('REVERB_APP_SECRET', 'aeroserve_secret'),
    'host' => env('REVERB_HOST', '127.0.0.1'),
    'port' => (int) env('REVERB_PORT', 8080),
    'scheme' => env('REVERB_SCHEME', 'http'),
    'use_tls' => env('REVERB_SCHEME', 'http') === 'https',
],
        'log' => [
            'driver' => 'log',
        ],
    ],
];

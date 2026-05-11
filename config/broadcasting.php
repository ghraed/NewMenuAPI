<?php

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY', env('PUSHER_APP_KEY')),
            'secret' => env('REVERB_APP_SECRET', env('PUSHER_APP_SECRET')),
            'app_id' => env('REVERB_APP_ID', env('PUSHER_APP_ID')),
            'options' => [
                'host' => env('REVERB_HOST', env('PUSHER_HOST')),
                'port' => env('REVERB_PORT', env('PUSHER_PORT', 443)),
                'scheme' => env('REVERB_SCHEME', env('PUSHER_SCHEME', 'https')),
                'useTLS' => env('REVERB_SCHEME', env('PUSHER_SCHEME', 'https')) === 'https',
            ],
            'client_options' => [],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST', env('PUSHER_APP_CLUSTER')
                    ? 'api-'.env('PUSHER_APP_CLUSTER', 'mt1').'.pusher.com'
                    : 'api-mt1.pusher.com'),
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];

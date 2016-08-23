<?php

return [

    'default' => 'database',

    'connections' => [

        'database' => [
            'driver' => 'rethinkdb',
            'table'  => 'jobs',
            'queue'  => 'default',
            'expire' => 60,
        ],

    ],

    'failed' => [
        'database' => 'mongodb',
        'table'    => 'failed_jobs',
    ],

];

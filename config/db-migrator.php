<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Migration Sequence
    |--------------------------------------------------------------------------
    |
    | Here you can define the order in which your migration classes should run.
    | Migrations listed here will be executed first, in the order specified,
    | before any other discovered migrations.
    |
    */

    'sequence' => [],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the status monitoring and watcher commands.
    |
    | interval: Seconds between refreshes in --continues-stats mode.
    |
    */

    'monitoring' => [
        'interval' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | Specify the table names used by the migrator.
    |
    */

    'tables' => [
        'db_migrator' => 'db_migrators',
        'migrator_history' => 'migrator_histories',
    ],

    'worker' => [
        'delay' => 5,
        'sleep' => 3,
    ],

    'model_connection' => null
];

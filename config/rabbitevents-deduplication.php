<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The Laravel cache store that keeps handled message IDs. It must be shared
    | by all workers of a queue (redis, database, memcached; file only when all
    | of them run on one host). `null` uses the default cache store.
    |
    */

    'store' => env('RABBITEVENTS_DEDUPLICATION_STORE'),

    /*
    |--------------------------------------------------------------------------
    | TTL
    |--------------------------------------------------------------------------
    |
    | How many seconds a handled message ID is remembered. Redeliveries come
    | soon after the failure, so a day is plenty.
    |
    */

    'ttl' => env('RABBITEVENTS_DEDUPLICATION_TTL', 86400),
];

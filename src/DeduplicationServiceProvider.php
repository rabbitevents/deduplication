<?php

declare(strict_types=1);

namespace RabbitEvents\Deduplication;

use Illuminate\Support\ServiceProvider;
use RabbitEvents\Listener\Contracts\Deduplicator;

class DeduplicationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/rabbitevents-deduplication.php', 'rabbitevents-deduplication');

        // Resolved when a worker starts: a store missing from config/cache.php stops it right away.
        $this->app->singleton(Deduplicator::class, static fn($app) => new CacheDeduplicator(
            $app['cache']->store($app['config']->get('rabbitevents-deduplication.store')),
            $app['log'],
            (int) $app['config']->get('rabbitevents-deduplication.ttl', 86400)
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/rabbitevents-deduplication.php' => $this->app->configPath('rabbitevents-deduplication.php'),
        ], 'rabbitevents-deduplication');
    }
}

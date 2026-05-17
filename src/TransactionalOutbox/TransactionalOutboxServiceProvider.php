<?php

declare(strict_types=1);

namespace TransactionalOutbox;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use TransactionalOutbox\Commands\ProcessMessagesCommand;
use TransactionalOutbox\Events\MessageConsumed;
use TransactionalOutbox\Listeners\OnMessageConsumed;

class TransactionalOutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/transactional-outbox.php', 'transactional-outbox');
    }

    public function boot(): void
    {
        Event::listen(MessageConsumed::class, OnMessageConsumed::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/transactional-outbox.php' => config_path('transactional-outbox.php'),
            ], 'transactional-outbox-config');

            $this->publishes([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'transactional-outbox-migrations');

            $this->commands([
                ProcessMessagesCommand::class,
            ]);
        }
    }
}

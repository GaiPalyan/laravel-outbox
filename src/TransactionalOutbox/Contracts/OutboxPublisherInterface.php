<?php

declare(strict_types=1);

namespace TransactionalOutbox\Contracts;

use TransactionalOutbox\Models\OutboxMessage;

interface OutboxPublisherInterface
{
    /**
     * Publish the outbox message to the transport layer.
     * Must throw on failure.
     */
    public function publish(OutboxMessage $message): void;
}

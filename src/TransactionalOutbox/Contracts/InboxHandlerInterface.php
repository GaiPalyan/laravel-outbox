<?php

declare(strict_types=1);

namespace TransactionalOutbox\Contracts;

use TransactionalOutbox\Models\InboxMessage;

interface InboxHandlerInterface
{
    /**
     * Process a received inbox message.
     * Must throw on failure.
     */
    public function handle(InboxMessage $message): void;
}

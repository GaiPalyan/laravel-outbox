<?php

declare(strict_types=1);

namespace TransactionalOutbox\Events;

use TransactionalOutbox\Models\OutboxMessage;

final readonly class OutboxMessageSent
{
    public function __construct(
        public OutboxMessage $message,
    ) {}
}

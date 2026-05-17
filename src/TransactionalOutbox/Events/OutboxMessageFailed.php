<?php

declare(strict_types=1);

namespace TransactionalOutbox\Events;

use TransactionalOutbox\Models\OutboxMessage;

final readonly class OutboxMessageFailed
{
    public function __construct(
        public OutboxMessage $message,
    ) {}
}

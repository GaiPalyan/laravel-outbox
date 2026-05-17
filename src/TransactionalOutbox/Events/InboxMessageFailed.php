<?php

declare(strict_types=1);

namespace TransactionalOutbox\Events;

use TransactionalOutbox\Models\InboxMessage;

final readonly class InboxMessageFailed
{
    public function __construct(
        public InboxMessage $message,
    ) {}
}

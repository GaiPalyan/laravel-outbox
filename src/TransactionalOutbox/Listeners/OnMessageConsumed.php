<?php

declare(strict_types=1);

namespace TransactionalOutbox\Listeners;

use TransactionalOutbox\Events\MessageConsumed;
use TransactionalOutbox\Models\InboxMessage;

class OnMessageConsumed
{
    public function handle(MessageConsumed $event): void
    {
        InboxMessage::store(
            channel: $event->channel,
            payload: $event->payload,
            headers: $event->headers,
        );
    }
}

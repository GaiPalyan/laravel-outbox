<?php

declare(strict_types=1);

namespace TransactionalOutbox\Events;

final readonly class MessageConsumed
{
    /**
     * @param  string  $deduplicationKey  Idempotency key of the received message
     *                                    (usually the broker message id), used to
     *                                    dedup re-deliveries on the inbox side.
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public string $channel,
        public string $payload,
        public string $deduplicationKey,
        public array $headers,
    ) {}
}

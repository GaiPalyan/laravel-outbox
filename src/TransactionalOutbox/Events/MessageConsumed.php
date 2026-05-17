<?php

declare(strict_types=1);

namespace TransactionalOutbox\Events;

final readonly class MessageConsumed
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public string $channel,
        public string $payload,
        public array $headers
    ) {}
}

<?php

declare(strict_types=1);

namespace TransactionalOutbox\Data;

/**
 * A single message to be enqueued via OutboxMessage::storeBatch().
 *
 * The deduplication key is a required constructor argument by design: identity
 * of a logical message is domain knowledge the caller owns, and making it a
 * typed field means it cannot be silently omitted in a batch (no runtime array
 * inspection needed). Use OutboxMessage::hashPayload($payload) explicitly if you
 * want content-addressed dedup.
 */
final readonly class OutboxDraft
{
    /**
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public string $channel,
        public string $payload,
        public string $deduplicationKey,
        public array $headers = [],
    ) {}
}

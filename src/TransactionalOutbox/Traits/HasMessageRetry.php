<?php

declare(strict_types=1);

namespace TransactionalOutbox\Traits;

use Illuminate\Support\Carbon;

trait HasMessageRetry
{
    // -------------------------------------------------------------------------
    // Outbox: terminates when exponential delay reaches max_delay cap
    // -------------------------------------------------------------------------

    protected function calculateOutboxBackoff(int $attempts): int
    {
        $exp = config('transactional-outbox.outbox.backoff', 2) ** $attempts;
        $jitter = (int) ceil($exp * config('transactional-outbox.outbox.jitter', 0.2));
        $final = $exp + random_int(0, $jitter);
        $maxDelay = config('transactional-outbox.outbox.max_delay_between_attempts', 86400);

        return (int) min($final, $maxDelay);
    }

    protected function isOutboxMaxAttemptsExceeded(int $attempts): bool
    {
        $max = config('transactional-outbox.outbox.max_delay_between_attempts', 86400);

        return $this->calculateOutboxBackoff($attempts) >= $max;
    }

    protected function calculateOutboxNextRetryTime(int $attempts): Carbon
    {
        return Carbon::now()->addSeconds($this->calculateOutboxBackoff($attempts));
    }

    // -------------------------------------------------------------------------
    // Inbox: exponential backoff capped at max_delay_seconds
    // -------------------------------------------------------------------------

    protected function isInboxMaxAttemptsExceeded(int $attempts): bool
    {
        return $attempts >= config('transactional-outbox.inbox.max_attempts', 5);
    }

    protected function calculateInboxNextRetryTime(int $attempts): Carbon
    {
        $base = config('transactional-outbox.inbox.retry_delay_seconds', 15);
        $max = config('transactional-outbox.inbox.max_delay_seconds', 3600);
        $delay = (int) min($base * (2 ** ($attempts - 1)), $max);

        return Carbon::now()->addSeconds($delay);
    }
}

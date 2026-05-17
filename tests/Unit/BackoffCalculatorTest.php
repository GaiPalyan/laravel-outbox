<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use TransactionalOutbox\Traits\HasMessageRetry;

function makeRetrySubject(): object
{
    return new class
    {
        use HasMessageRetry;

        public function outboxBackoff(int $attempts): int
        {
            return $this->calculateOutboxBackoff($attempts);
        }

        public function outboxExceeded(int $attempts): bool
        {
            return $this->isOutboxMaxAttemptsExceeded($attempts);
        }

        public function outboxNextRetry(int $attempts): Carbon
        {
            return $this->calculateOutboxNextRetryTime($attempts);
        }

        public function inboxExceeded(int $attempts): bool
        {
            return $this->isInboxMaxAttemptsExceeded($attempts);
        }

        public function inboxNextRetry(int $attempts): Carbon
        {
            return $this->calculateInboxNextRetryTime($attempts);
        }
    };
}

describe('outbox backoff', function () {
    beforeEach(function () {
        config([
            'transactional-outbox.outbox.backoff' => 2,
            'transactional-outbox.outbox.jitter' => 0.0,
            'transactional-outbox.outbox.max_delay_between_attempts' => 86400,
        ]);
        $this->subject = makeRetrySubject();
    });

    it('grows exponentially with each attempt', function (int $attempts, int $expected) {
        expect($this->subject->outboxBackoff($attempts))->toBe($expected);
    })->with([
        [1,  2],
        [2,  4],
        [3,  8],
        [4,  16],
        [10, 1024],
    ]);

    it('is capped at max_delay_between_attempts', function () {
        config(['transactional-outbox.outbox.max_delay_between_attempts' => 100]);

        expect($this->subject->outboxBackoff(10))->toBe(100);
    });

    it('applies jitter within expected range', function () {
        config(['transactional-outbox.outbox.jitter' => 0.2]);

        $results = array_map(fn () => $this->subject->outboxBackoff(3), range(1, 200));

        // base=2^3=8, jitter=ceil(8*0.2)=2 → range [8..10]
        expect(min($results))->toBeGreaterThanOrEqual(8)
            ->and(max($results))->toBeLessThanOrEqual(10)
            ->and(array_unique($results))->toBeGreaterThan(1);
    });

    it('is not exceeded when delay is below max', function () {
        expect($this->subject->outboxExceeded(1))->toBeFalse();
    });

    it('is exceeded when delay reaches max', function () {
        config(['transactional-outbox.outbox.max_delay_between_attempts' => 4]);

        // 2^2 = 4, jitter=0 → 4 >= 4 → exceeded
        expect($this->subject->outboxExceeded(2))->toBeTrue();
    });

    it('returns a future Carbon for next retry', function () {
        $before = Carbon::now();
        $retry = $this->subject->outboxNextRetry(3);

        expect($retry->greaterThan($before))->toBeTrue();
    });
});

describe('inbox backoff', function () {
    beforeEach(function () {
        config([
            'transactional-outbox.inbox.max_attempts' => 5,
            'transactional-outbox.inbox.retry_delay_seconds' => 15,
            'transactional-outbox.inbox.max_delay_seconds' => 3600,
        ]);
        $this->subject = makeRetrySubject();
    });

    it('grows exponentially: base * 2^(attempts-1)', function (int $attempts, int $expectedSeconds) {
        $retry = $this->subject->inboxNextRetry($attempts);
        $diff = (int) Carbon::now()->diffInSeconds($retry);

        expect($diff)->toBeGreaterThanOrEqual($expectedSeconds - 1)
            ->and($diff)->toBeLessThanOrEqual($expectedSeconds + 1);
    })->with([
        [1, 15],
        [2, 30],
        [3, 60],
        [4, 120],
        [5, 240],
    ]);

    it('is capped at max_delay_seconds', function () {
        config(['transactional-outbox.inbox.max_delay_seconds' => 60]);

        $retry = $this->subject->inboxNextRetry(10);
        $diff = (int) Carbon::now()->diffInSeconds($retry);

        expect($diff)->toBeLessThanOrEqual(61);
    });

    it('is not exceeded below max_attempts', function () {
        expect($this->subject->inboxExceeded(4))->toBeFalse();
    });

    it('is exceeded at max_attempts', function () {
        expect($this->subject->inboxExceeded(5))->toBeTrue();
    });

    it('is exceeded above max_attempts', function () {
        expect($this->subject->inboxExceeded(6))->toBeTrue();
    });
});

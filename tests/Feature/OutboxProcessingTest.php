<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use TransactionalOutbox\Contracts\OutboxPublisherInterface;
use TransactionalOutbox\Enums\OutboxStatus;
use TransactionalOutbox\Events\OutboxMessageFailed;
use TransactionalOutbox\Events\OutboxMessageSent;
use TransactionalOutbox\Jobs\ProcessOutboxMessageJob;
use TransactionalOutbox\Models\OutboxMessage;

describe('ProcessOutboxMessageJob', function () {
    beforeEach(function () {
        $this->message = OutboxMessage::store('orders', '{"id":1}');
    });

    describe('failed()', function () {
        it('marks as pending with retry when below max delay', function () {
            new ProcessOutboxMessageJob($this->message->id)
                ->failed(new RuntimeException('Broker unavailable'));

            $message = $this->message->fresh();

            expect($message->status_id)->toBe(OutboxStatus::Pending->id())
                ->and($message->last_error_text)->toBe('Broker unavailable')
                ->and($message->attempts)->toBe(1)
                ->and($message->next_retry_at)->not->toBeNull();
        });

        it('marks as permanently failed when max delay exceeded', function () {
            Event::fake([OutboxMessageFailed::class]);
            config(['transactional-outbox.outbox.max_delay_between_attempts' => 1]);

            new ProcessOutboxMessageJob($this->message->id)
                ->failed(new RuntimeException('Broker unavailable'));

            expect($this->message->fresh()->status_id)->toBe(OutboxStatus::Failed->id());
            Event::assertDispatched(OutboxMessageFailed::class, fn ($e) => $e->message->id === $this->message->id);
        });

        it('does nothing when message no longer exists', function () {
            $this->message->delete();

            expect(fn () => new ProcessOutboxMessageJob($this->message->id)
                ->failed(new RuntimeException('error')),
            )->not->toThrow(Throwable::class);
        });
    });

    describe('ProcessMessagesCommand', function () {
        it('publishes message end-to-end and marks as sent', function () {
            Event::fake([OutboxMessageSent::class]);
            config(['queue.default' => 'sync']);

            $this->app->bind(OutboxPublisherInterface::class, fn () => new class implements OutboxPublisherInterface
            {
                public function publish(OutboxMessage $message): void {}
            });

            $this->artisan('transactional-outbox:process', ['type' => 'outbox'])
                ->assertSuccessful();

            expect($this->message->fresh()->status_id)->toBe(OutboxStatus::Sent->id());
            Event::assertDispatched(OutboxMessageSent::class, fn ($e) => $e->message->id === $this->message->id);
        });

        it('dispatches pending messages and marks them in_progress', function () {
            Queue::fake();

            $this->artisan('transactional-outbox:process', ['type' => 'outbox'])
                ->assertSuccessful();

            Queue::assertPushed(ProcessOutboxMessageJob::class, 1);

            expect($this->message->fresh()->status_id)
                ->toBe(OutboxStatus::InProgress->id());
        });

        it('skips future-scheduled messages', function () {
            Queue::fake();

            $this->message->forceFill(['next_retry_at' => Carbon::now()->addHour()])->save();

            $this->artisan('transactional-outbox:process', ['type' => 'outbox'])
                ->assertSuccessful();

            Queue::assertNothingPushed();

            expect($this->message->fresh()->status_id)
                ->toBe(OutboxStatus::Pending->id());
        });

        it('resets stuck messages and re-dispatches', function () {
            Queue::fake();

            $this->message->markAsInProgress(Carbon::now()->subMinutes(5));

            $this->artisan('transactional-outbox:process', ['type' => 'outbox'])
                ->assertSuccessful();

            Queue::assertPushed(ProcessOutboxMessageJob::class, 1);
        });

        it('returns invalid exit code for unknown type', function () {
            $this->artisan('transactional-outbox:process', ['type' => 'unknown'])
                ->assertFailed();
        });
    });
});

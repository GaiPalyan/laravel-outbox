<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use TransactionalOutbox\Contracts\InboxHandlerInterface;
use TransactionalOutbox\Enums\InboxStatus;
use TransactionalOutbox\Events\InboxMessageFailed;
use TransactionalOutbox\Events\InboxMessageProcessed;
use TransactionalOutbox\Events\MessageConsumed;
use TransactionalOutbox\Jobs\ProcessInboxMessageJob;
use TransactionalOutbox\Models\InboxMessage;

describe('OnMessageConsumed + ProcessMessagesCommand', function () {
    it('listener creates message from event and command marks it as processed', function () {
        Event::fake([InboxMessageProcessed::class]);

        app()->bind('orders', function () {
            return new class implements InboxHandlerInterface
            {
                public function handle(InboxMessage $message): void {}
            };
        });

        expect(InboxMessage::count())->toBe(0);

        event(new MessageConsumed(
            channel: 'orders',
            payload: '{"id":1}',
            headers: [],
        ));

        expect(InboxMessage::count())->toBe(1);

        $this->artisan('transactional-outbox:process', ['type' => 'inbox'])
            ->assertSuccessful();

        expect(InboxMessage::first()->status_id)->toBe(InboxStatus::Processed->id());
        Event::assertDispatched(InboxMessageProcessed::class);
    });
});

describe('ProcessInboxMessageJob', function () {
    beforeEach(function () {
        $this->message = InboxMessage::store('orders', '{"id":1}');
    });

    describe('failed()', function () {
        it('marks as pending with retry when below max attempts', function () {
            new ProcessInboxMessageJob($this->message->id)
                ->failed(new RuntimeException('Handler failed'));

            $message = $this->message->fresh();

            expect($message->status_id)->toBe(InboxStatus::Pending->id())
                ->and($message->last_error_text)->toBe('Handler failed')
                ->and($message->attempts)->toBe(1)
                ->and($message->next_retry_at)->not->toBeNull();
        });

        it('marks as permanently failed after max attempts', function () {
            Event::fake([InboxMessageFailed::class]);
            config(['transactional-outbox.inbox.max_attempts' => 1]);

            new ProcessInboxMessageJob($this->message->id)
                ->failed(new RuntimeException('Handler failed'));

            expect($this->message->fresh()->status_id)->toBe(InboxStatus::Failed->id());
            Event::assertDispatched(InboxMessageFailed::class, fn ($e) => $e->message->id === $this->message->id);
        });

        it('does nothing when message no longer exists', function () {
            $this->message->delete();

            expect(fn () => new ProcessInboxMessageJob($this->message->id)
                ->failed(new RuntimeException('error')),
            )->not->toThrow(Throwable::class);
        });
    });

    describe('ProcessMessagesCommand', function () {
        it('dispatches pending messages and marks them in_progress', function () {
            Queue::fake();

            $this->artisan('transactional-outbox:process', ['type' => 'inbox'])
                ->assertSuccessful();

            Queue::assertPushed(ProcessInboxMessageJob::class, 1);

            expect($this->message->fresh()->status_id)
                ->toBe(InboxStatus::InProgress->id());
        });

        it('skips future-scheduled messages', function () {
            Queue::fake();

            $this->message->forceFill(['next_retry_at' => Carbon::now()->addHour()])->save();

            $this->artisan('transactional-outbox:process', ['type' => 'inbox'])
                ->assertSuccessful();

            Queue::assertNothingPushed();

            expect($this->message->fresh()->status_id)
                ->toBe(InboxStatus::Pending->id());
        });

        it('resets stuck messages and re-dispatches', function () {
            Queue::fake();

            $this->message->markAsInProgress(Carbon::now()->subMinutes(10));

            $this->artisan('transactional-outbox:process', ['type' => 'inbox'])
                ->assertSuccessful();

            Queue::assertPushed(ProcessInboxMessageJob::class, 1);
        });
    });
});

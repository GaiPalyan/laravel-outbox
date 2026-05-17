<?php

declare(strict_types=1);

namespace TransactionalOutbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;
use TransactionalOutbox\Contracts\OutboxPublisherInterface;
use TransactionalOutbox\Events\OutboxMessageFailed;
use TransactionalOutbox\Events\OutboxMessageSent;
use TransactionalOutbox\Models\OutboxMessage;
use TransactionalOutbox\Traits\HasMessageRetry;

class ProcessOutboxMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use HasMessageRetry;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(private readonly string $messageId) {}

    public function handle(OutboxPublisherInterface $publisher): void
    {
        $message = OutboxMessage::findOrFail($this->messageId);
        $publisher->publish($message);
        $message->markAsSent();
        event(new OutboxMessageSent($message));
    }

    public function failed(Throwable $e): void
    {
        $message = OutboxMessage::find($this->messageId);
        if (! $message) {
            return;
        }

        $attempts = $message->attempts + 1;

        if ($this->isOutboxMaxAttemptsExceeded($attempts)) {
            $message->markAsFailed($e->getMessage());
            event(new OutboxMessageFailed($message));
        } else {
            $message->markAsPendingWithRetry(
                $this->calculateOutboxNextRetryTime($attempts),
                $e->getMessage(),
            );
        }
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))->expireAfter(3600)->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        return 'outbox_publish_'.$this->messageId;
    }
}

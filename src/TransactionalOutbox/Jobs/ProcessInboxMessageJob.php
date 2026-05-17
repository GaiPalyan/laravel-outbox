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
use TransactionalOutbox\Events\InboxMessageFailed;
use TransactionalOutbox\Events\InboxMessageProcessed;
use TransactionalOutbox\InboxHandler;
use TransactionalOutbox\Models\InboxMessage;
use TransactionalOutbox\Traits\HasMessageRetry;

class ProcessInboxMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use HasMessageRetry;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(private readonly string $messageId) {}

    public function handle(InboxHandler $handler): void
    {
        $message = InboxMessage::findOrFail($this->messageId);

        $handler($message);

        $message->markAsProcessed();
        event(new InboxMessageProcessed($message));
    }

    public function failed(Throwable $e): void
    {
        $message = InboxMessage::find($this->messageId);
        if (! $message) {
            return;
        }

        $attempts = $message->attempts + 1;

        if ($this->isInboxMaxAttemptsExceeded($attempts)) {
            $message->markAsFailed($e->getMessage());
            event(new InboxMessageFailed($message));
        } else {
            $message->markAsPendingWithRetry(
                $this->calculateInboxNextRetryTime($attempts),
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
            new WithoutOverlapping($this->uniqueId())->expireAfter(3600)->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        return 'inbox_process_'.$this->messageId;
    }
}

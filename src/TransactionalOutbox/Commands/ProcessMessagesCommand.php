<?php

declare(strict_types=1);

namespace TransactionalOutbox\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Command\Command as CommandAlias;
use TransactionalOutbox\Jobs\ProcessInboxMessageJob;
use TransactionalOutbox\Jobs\ProcessOutboxMessageJob;
use TransactionalOutbox\Models\InboxMessage;
use TransactionalOutbox\Models\OutboxMessage;

class ProcessMessagesCommand extends Command
{
    protected $signature = 'transactional-outbox:process
                            {type : outbox or inbox}
                            {--limit=100 : Maximum number of messages per run}';

    protected $description = 'Dispatch pending outbox or inbox messages';

    public function handle(): int
    {
        return match ($this->argument('type')) {
            'outbox' => $this->processOutbox(),
            'inbox' => $this->processInbox(),
            default => CommandAlias::INVALID,
        };
    }

    private function processOutbox(): int
    {
        OutboxMessage::resetStuck();

        $messages = OutboxMessage::inReadyToProcess()
            ->latest()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($messages->isEmpty()) {
            return CommandAlias::SUCCESS;
        }

        $deadline = Carbon::now()->addSeconds(
            config('transactional-outbox.outbox.in_progress_deadline', 60),
        );

        foreach ($messages as $message) {
            if ($message->markAsInProgress($deadline)) {
                dispatch(new ProcessOutboxMessageJob($message->id));
            }
        }

        return CommandAlias::SUCCESS;
    }

    private function processInbox(): int
    {
        InboxMessage::resetStuck();

        $messages = InboxMessage::inReadyToProcess()
            ->latest()
            ->limit((int) $this->option('limit'))
            ->get();

        if ($messages->isEmpty()) {
            return CommandAlias::SUCCESS;
        }

        $deadline = Carbon::now()->addSeconds(
            config('transactional-outbox.inbox.in_progress_deadline', 300),
        );

        foreach ($messages as $message) {
            if ($message->markAsInProgress($deadline)) {
                dispatch(new ProcessInboxMessageJob($message->id));
            }
        }

        return CommandAlias::SUCCESS;
    }
}

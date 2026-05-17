<?php

declare(strict_types=1);

namespace TransactionalOutbox\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use TransactionalOutbox\Enums\Database\InboxMessageColumn;
use TransactionalOutbox\Enums\InboxStatus;
use TransactionalOutbox\Models\InboxMessage;

/**
 * @extends Builder<InboxMessage>
 */
final class InboxMessageBuilder extends Builder
{
    public function inPending(): self
    {
        return $this->where(InboxMessageColumn::STATUS->value, InboxStatus::Pending->id());
    }

    public function inProgress(): self
    {
        return $this->where(InboxMessageColumn::STATUS->value, InboxStatus::InProgress->id());
    }

    public function inProcessed(): self
    {
        return $this->where(InboxMessageColumn::STATUS->value, InboxStatus::Processed->id());
    }

    public function inFailed(): self
    {
        return $this->where(InboxMessageColumn::STATUS->value, InboxStatus::Failed->id());
    }

    public function inReadyToProcess(): self
    {
        return $this->inPending()
            ->where(InboxMessageColumn::NEXT_RETRY_AT->value, '<=', Carbon::now());
    }

    public function inStuck(): self
    {
        return $this->inProgress()
            ->where(InboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value, '<', Carbon::now());
    }

    public function finishedOlderThan(Carbon $cutoffTime): self
    {
        return $this->inProcessed()
            ->where(InboxMessageColumn::CREATED_AT->value, '<', $cutoffTime);
    }
}

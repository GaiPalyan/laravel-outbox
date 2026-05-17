<?php

declare(strict_types=1);

namespace TransactionalOutbox\Models\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use TransactionalOutbox\Enums\Database\OutboxMessageColumn;
use TransactionalOutbox\Enums\OutboxStatus;
use TransactionalOutbox\Models\OutboxMessage;

/**
 * @extends Builder<OutboxMessage>
 */
final class OutboxMessageBuilder extends Builder
{
    public function inPending(): self
    {
        return $this->where(OutboxMessageColumn::STATUS->value, OutboxStatus::Pending->id());
    }

    public function inProgress(): self
    {
        return $this->where(OutboxMessageColumn::STATUS->value, OutboxStatus::InProgress->id());
    }

    public function inSent(): self
    {
        return $this->where(OutboxMessageColumn::STATUS->value, OutboxStatus::Sent->id());
    }

    public function inFailed(): self
    {
        return $this->where(OutboxMessageColumn::STATUS->value, OutboxStatus::Failed->id());
    }

    public function inReadyToProcess(): self
    {
        return $this->inPending()
            ->where(OutboxMessageColumn::NEXT_RETRY_AT->value, '<=', Carbon::now());
    }

    public function inStuck(): self
    {
        return $this->inProgress()
            ->where(OutboxMessageColumn::IN_PROGRESS_DEADLINE_AT->value, '<', Carbon::now());
    }

    public function finishedOlderThan(Carbon $cutoffTime): self
    {
        return $this->inSent()
            ->where(OutboxMessageColumn::CREATED_AT->value, '<', $cutoffTime);
    }
}

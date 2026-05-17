<?php

declare(strict_types=1);

namespace TransactionalOutbox\Enums;

enum InboxStatus: string
{
    case Pending = 'PENDING';
    case InProgress = 'IN_PROGRESS';
    case Processed = 'PROCESSED';
    case Failed = 'FAILED';

    public function id(): int
    {
        return match ($this) {
            self::Pending => 1,
            self::InProgress => 2,
            self::Processed => 3,
            self::Failed => 4,
        };
    }

    public static function fromId(int $id): self
    {
        return match ($id) {
            1 => self::Pending,
            2 => self::InProgress,
            3 => self::Processed,
            4 => self::Failed,
            default => throw new \ValueError("Unknown InboxStatus id: {$id}"),
        };
    }

    public function canChangeTo(self $status): bool
    {
        return match ($this) {
            self::Pending => $status === self::InProgress,
            self::InProgress => in_array($status, [self::Processed, self::Pending, self::Failed]),
            self::Processed,
            self::Failed => false,
        };
    }
}

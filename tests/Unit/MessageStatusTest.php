<?php

declare(strict_types=1);

use TransactionalOutbox\Enums\InboxStatus;
use TransactionalOutbox\Enums\OutboxStatus;

describe('OutboxStatus', function () {
    describe('canChangeTo()', function () {
        it('allows valid transitions', function (OutboxStatus $from, OutboxStatus $to) {
            expect($from->canChangeTo($to))->toBeTrue();
        })->with([
            'pending → in_progress' => [OutboxStatus::Pending,    OutboxStatus::InProgress],
            'in_progress → sent' => [OutboxStatus::InProgress, OutboxStatus::Sent],
            'in_progress → pending' => [OutboxStatus::InProgress, OutboxStatus::Pending],
            'in_progress → failed' => [OutboxStatus::InProgress, OutboxStatus::Failed],
        ]);

        it('rejects invalid transitions', function (OutboxStatus $from, OutboxStatus $to) {
            expect($from->canChangeTo($to))->toBeFalse();
        })->with([
            'pending → sent' => [OutboxStatus::Pending,    OutboxStatus::Sent],
            'pending → failed' => [OutboxStatus::Pending,    OutboxStatus::Failed],
            'pending → pending' => [OutboxStatus::Pending,    OutboxStatus::Pending],
            'sent → any' => [OutboxStatus::Sent,       OutboxStatus::Pending],
            'failed → any' => [OutboxStatus::Failed,     OutboxStatus::Pending],
        ]);
    });
});

describe('InboxStatus', function () {
    describe('canChangeTo()', function () {
        it('allows valid transitions', function (InboxStatus $from, InboxStatus $to) {
            expect($from->canChangeTo($to))->toBeTrue();
        })->with([
            'pending → in_progress' => [InboxStatus::Pending,    InboxStatus::InProgress],
            'in_progress → processed' => [InboxStatus::InProgress, InboxStatus::Processed],
            'in_progress → pending' => [InboxStatus::InProgress, InboxStatus::Pending],
            'in_progress → failed' => [InboxStatus::InProgress, InboxStatus::Failed],
        ]);

        it('rejects invalid transitions', function (InboxStatus $from, InboxStatus $to) {
            expect($from->canChangeTo($to))->toBeFalse();
        })->with([
            'pending → processed' => [InboxStatus::Pending,    InboxStatus::Processed],
            'pending → failed' => [InboxStatus::Pending,    InboxStatus::Failed],
            'pending → pending' => [InboxStatus::Pending,    InboxStatus::Pending],
            'processed → any' => [InboxStatus::Processed,  InboxStatus::Pending],
            'failed → any' => [InboxStatus::Failed,      InboxStatus::Pending],
        ]);
    });
});

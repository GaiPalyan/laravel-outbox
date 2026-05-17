<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Outbox Message Processing
    |--------------------------------------------------------------------------
    |
    | Configuration for the outbox pattern message processing including
    | retry backoff settings and message cleanup parameters.
    |
    */
    'outbox' => [
        'backoff' => (int) env('OUTBOX__RETRY_BACKOFF', 2),
        'jitter' => (float) env('OUTBOX__RETRY_JITTER', 0.2),
        'max_delay_between_attempts' => (int) env('OUTBOX__RETRY_MAX_DELAY', 86400),
        'in_progress_deadline' => (int) env('OUTBOX__IN_PROGRESS_DEADLINE', 60),
        'prune_after_days' => (int) env('OUTBOX__PRUNE_AFTER_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbox Message Processing
    |--------------------------------------------------------------------------
    |
    | Configuration for the inbox pattern message processing including
    | retry settings and message cleanup parameters.
    |
    */
    'inbox' => [
        'max_attempts' => (int) env('INBOX__MAX_ATTEMPTS', 5),
        'retry_delay_seconds' => (int) env('INBOX__RETRY_DELAY_SECONDS', 15),
        'max_delay_seconds' => (int) env('INBOX__MAX_DELAY_SECONDS', 3600),
        'in_progress_deadline' => (int) env('INBOX__IN_PROGRESS_DEADLINE', 300),
        'prune_after_days' => (int) env('INBOX__PRUNE_AFTER_DAYS', 30),
    ],
];

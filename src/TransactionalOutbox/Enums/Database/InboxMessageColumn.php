<?php

declare(strict_types=1);

namespace TransactionalOutbox\Enums\Database;

enum InboxMessageColumn: string
{
    case TABLE = 'inbox_messages';
    case ID = 'id';
    case CHANNEL = 'channel';
    case HEADERS = 'headers';
    case PAYLOAD = 'payload';
    case STATUS = 'status_id';
    case ATTEMPTS = 'attempts';
    case MAX_ATTEMPTS = 'max_attempts';
    case NEXT_RETRY_AT = 'next_retry_at';
    case IN_PROGRESS_DEADLINE_AT = 'in_progress_deadline_at';
    case DEDUPLICATION_KEY = 'deduplication_key';
    case LAST_ERROR_TEXT = 'last_error_text';
    case FAILED_AT = 'failed_at';
    case PROCESSED_AT = 'processed_at';
    case CREATED_AT = 'created_at';
    case UPDATED_AT = 'updated_at';
}

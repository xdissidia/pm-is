<?php

namespace App\Enums;

use Illuminate\Support\Str;

enum StormTicketWorkStatus: string
{
    case OPEN = 'open';
    case ONGOING = 'ongoing';
    case RESOLVED = 'resolved';
    case UNFINISHED = 'unfinished';

    /**
     * Does this state mean the work is finished? Resolved and unfinished both
     * close out the work — unfinished is "closed without fixing it", which is
     * why it also brands the task title (see UpdateTaskAttributes).
     */
    public function completes(): bool
    {
        return $this === self::RESOLVED || $this === self::UNFINISHED;
    }

    /**
     * Forgiving parse, the same way StormTicketStatus reads its statuses —
     * "Resolved", "closed (resolved)" and "closedresolved" all mean the same
     * thing to a caller. Null when nothing matches, so validation can report
     * it rather than this silently picking a default.
     */
    public static function fromLoose(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return match (preg_replace('/[^a-z0-9]/', '', Str::lower($value))) {
            'open' => self::OPEN,
            'ongoing' => self::ONGOING,
            'resolved', 'closedresolved' => self::RESOLVED,
            'unfinished', 'closedunfinished' => self::UNFINISHED,
            default => null,
        };
    }
}

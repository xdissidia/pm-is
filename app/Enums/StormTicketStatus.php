<?php

namespace App\Enums;

use App\Models\TaskGroup;
use Illuminate\Support\Str;

enum StormTicketStatus: string
{
    case OPEN = 'Open';
    case ON_GOING = 'On-going';
    case CLOSED = 'Closed';

    /**
     * The task group a ticket in this state belongs in: closed tickets land in
     * "Done", everything still live sits in the "STORM" group.
     */
    public function taskGroupName(): string
    {
        return $this === self::CLOSED ? 'Done' : 'STORM';
    }

    /**
     * That group inside a given project, or null if the project does not have
     * one. Matched on LOWER() so it does not depend on the database collation.
     */
    public function taskGroupIn(int $projectId): ?TaskGroup
    {
        return TaskGroup::where('project_id', $projectId)
            ->whereRaw('LOWER(name) = ?', [Str::lower($this->taskGroupName())])
            ->first();
    }

    /**
     * Forgiving parse — "on going", "ONGOING" and "on-going" all mean the same
     * thing to a caller. Returns null when nothing matches, so validation can
     * report it rather than this silently picking a default.
     */
    public static function fromLoose(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        return match (preg_replace('/[^a-z0-9]/', '', Str::lower($value))) {
            'open' => self::OPEN,
            'ongoing' => self::ON_GOING,
            'closed' => self::CLOSED,
            default => null,
        };
    }
}

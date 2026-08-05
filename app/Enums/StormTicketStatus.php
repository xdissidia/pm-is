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
     * The board column live tickets sit in — where STORM's tickets land, and
     * the one PMIS files new tickets from.
     */
    public const LIVE_GROUP = 'STORM';

    public const DONE_GROUP = 'Done';

    /**
     * The task group a ticket in this state belongs in: closed tickets land in
     * "Done", everything still live sits in the "STORM" group.
     */
    public function taskGroupName(): string
    {
        return $this === self::CLOSED ? self::DONE_GROUP : self::LIVE_GROUP;
    }

    /**
     * The inverse of taskGroupName(): the state a task sitting in this group
     * represents. "Done" is closed, "STORM" is open — the ticket is back where
     * it started — and any other column means someone pulled it into their
     * workflow, which is what On-going describes.
     */
    public static function forTaskGroup(?string $groupName): self
    {
        return match (true) {
            $groupName !== null && Str::lower($groupName) === Str::lower(self::DONE_GROUP) => self::CLOSED,
            self::isLiveGroup($groupName) => self::OPEN,
            default => self::ON_GOING,
        };
    }

    /**
     * Is this the group tickets are filed from? Matched case-insensitively,
     * the same way taskGroupIn() looks the group up.
     */
    public static function isLiveGroup(?string $groupName): bool
    {
        return $groupName !== null && Str::lower($groupName) === Str::lower(self::LIVE_GROUP);
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

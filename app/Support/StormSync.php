<?php

namespace App\Support;

class StormSync
{
    protected static bool $suspended = false;

    /**
     * Run something without pushing the task changes it makes back to STORM.
     *
     * Used by the API STORM itself calls: STORM already knows about those
     * changes, and echoing them would bounce between the two systems.
     */
    public static function withoutSyncing(callable $callback): mixed
    {
        $previous = static::$suspended;
        static::$suspended = true;

        try {
            return $callback();
        } finally {
            static::$suspended = $previous;
        }
    }

    /**
     * Checked where the sync is *dispatched*, not where it runs — by the time
     * a queued job is picked up, the request that suspended it is long gone.
     */
    public static function suspended(): bool
    {
        return static::$suspended;
    }
}

<?php

namespace App\Listeners;

use App\Events\Task\AttachmentsUploaded;
use App\Jobs\SyncStormTicket;
use App\Support\StormSync;

/**
 * Files uploaded onto a task after it was created go up to its STORM ticket
 * too. Attachments present at creation ride along with the ticket itself —
 * CreateTask uploads those without firing this event.
 */
class ForwardAttachmentsToStorm
{
    public function handle(AttachmentsUploaded $event): void
    {
        if (StormSync::suspended()) {
            return;
        }

        // The event keeps its task private; every attachment points at it.
        $task = $event->attachments->first()?->task;

        if (! $task || blank($task->storm_ticket_id)) {
            return;
        }

        SyncStormTicket::dispatch($task, [], $event->attachments->pluck('id')->all());
    }
}

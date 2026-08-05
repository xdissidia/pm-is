<?php

namespace App\Observers;

use App\Jobs\SyncStormTicket;
use App\Models\Attachment;
use App\Support\StormSync;

class AttachmentObserver
{
    /**
     * Drop the file off the STORM ticket too. Hooked on the model rather than
     * the AttachmentDeleted event, because by the time that event is handled
     * the row — and with it STORM's id for the file — is already gone.
     */
    public function deleted(Attachment $attachment): void
    {
        if (blank($attachment->storm_attachment_id) || StormSync::suspended()) {
            return;
        }

        $task = $attachment->task;

        if (! $task || blank($task->storm_ticket_id)) {
            return;
        }

        SyncStormTicket::dispatch($task, ['remove_attachments' => [$attachment->storm_attachment_id]]);
    }
}

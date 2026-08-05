<?php

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\Task;
use App\Services\Storm\StormApiException;
use App\Services\Storm\StormTicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pushes a change made in PMIS onto the STORM ticket the task mirrors —
 * edited fields, newly uploaded attachments, or both.
 *
 * Dispatched from TaskObserver and ForwardAttachmentsToStorm, which are the
 * ones that decide *whether* to sync; this job only carries it out.
 */
class SyncStormTicket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $changes  STORM ticket fields (see StormTicketService::FIELDS)
     * @param  array<int, int>  $attachmentIds  PMIS attachments to upload alongside
     */
    public function __construct(
        public Task $task,
        public array $changes = [],
        public array $attachmentIds = [],
    ) {
        // Task changes commit inside a transaction — wait for it, so a worker
        // on another connection reads the new values rather than the old ones.
        // Set here rather than as a property: Queueable already declares one.
        $this->afterCommit();
    }

    public function handle(StormTicketService $storm): void
    {
        if (blank($this->task->storm_ticket_id) || blank(config('services.storm.token'))) {
            return;
        }

        $attachments = empty($this->attachmentIds)
            ? collect()
            : Attachment::whereIn('id', $this->attachmentIds)->get();

        $uploads = $attachments->isEmpty() ? [] : StormTicketService::uploadsFrom($attachments);

        if (empty($this->changes) && empty($uploads)) {
            return;
        }

        try {
            $ticket = $storm->update($this->task->storm_ticket_id, $this->changes, $uploads);

            StormTicketService::linkAttachments($attachments, $ticket);
        } catch (StormApiException $e) {
            // Best effort, same as filing: the change is already saved in PMIS
            // and a STORM outage must not fail the request that made it.
            Log::error("Syncing task {$this->task->id} to STORM ticket {$this->task->storm_ticket_id} failed: {$e->getMessage()}", [
                'task_id' => $this->task->id,
                'storm_ticket_id' => $this->task->storm_ticket_id,
                'changes' => array_keys($this->changes),
                'status' => $e->status,
                'errors' => $e->errors,
            ]);
        }
    }
}

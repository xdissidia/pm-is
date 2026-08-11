<?php

namespace App\Listeners;

use App\Enums\StormTicketStatus;
use App\Events\Task\TaskCreated;
use App\Models\Task;
use App\Services\Storm\StormApiException;
use App\Services\Storm\StormTicketService;
use App\Support\StormSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Files a STORM ticket for every task created in the STORM task group, and
 * stamps the new ticket id back onto the task.
 */
class FileStormTicket implements ShouldQueue
{
    /**
     * The task is created inside a transaction, so wait for it to commit —
     * otherwise a worker on another connection cannot see the row yet.
     */
    public bool $afterCommit = true;

    public function __construct(
        protected StormTicketService $storm,
    ) {}

    public function handle(TaskCreated $event): void
    {
        $task = $event->task;

        if (! $this->shouldFile($task)) {
            return;
        }

        try {
            $ticket = $this->storm->createFromTask(
                $task,
                ['status' => StormTicketStatus::OPEN] + $this->assignees($task),
                StormTicketService::uploadsFrom($task->attachments),
            );
        } catch (StormApiException $e) {
            // Best effort: the task is already created and the user is waiting
            // on the response, so a STORM outage must not fail the request.
            Log::error("Filing a STORM ticket for task {$task->id} failed: {$e->getMessage()}", [
                'task_id' => $task->id,
                'status' => $e->status,
                'errors' => $e->errors,
            ]);

            return;
        }

        $ticketId = $ticket['id'] ?? null;

        if ($ticketId === null) {
            Log::warning("STORM accepted the ticket for task {$task->id} but returned no id.", ['response' => $ticket]);

            return;
        }

        // Quietly: this is bookkeeping, not something to log an activity or
        // broadcast a task update for.
        $task->storm_ticket_id = $ticketId;
        $task->saveQuietly();

        StormTicketService::linkAttachments($task->attachments, $ticket);
    }

    /**
     * The ticket's assignees, by employee number — the one identifier both
     * systems share, so there is no users/lookup round-trip to resolve STORM's
     * own ids first. Assignees without a number are left for STORM's matching.
     *
     * @return array{assignees?: array<int, string>}
     */
    protected function assignees(Task $task): array
    {
        $numbers = $task->assignees
            ->pluck('employee_number')
            ->filter()
            ->values()
            ->all();

        return empty($numbers) ? [] : ['assignees' => $numbers];
    }

    /**
     * Only tasks in the STORM group, only once, and only when there is a token
     * to file with — a task that arrived *from* STORM already has a ticket.
     */
    protected function shouldFile(Task $task): bool
    {
        return blank($task->storm_ticket_id)
            && ! StormSync::suspended()
            && filled(config('services.storm.token'))
            && StormTicketStatus::isLiveGroup($task->taskGroup?->name);
    }
}

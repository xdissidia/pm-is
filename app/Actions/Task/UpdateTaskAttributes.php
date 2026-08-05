<?php

namespace App\Actions\Task;

use App\Enums\StormTicketStatus;
use App\Enums\StormTicketWorkStatus;
use App\Events\Task\AttachmentDeleted;
use App\Events\Task\TaskUpdated;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class UpdateTaskAttributes
{
    /**
     * API field name => the field name UpdateTask understands. Fields needing
     * more than a straight assignment (group, completion) are handled below.
     */
    protected array $fields = [
        'title' => 'name',
        'body' => 'description',
        'assignees' => 'assignees',
        'subscribers' => 'subscribed_users',
        'labels' => 'labels',
        'due_on' => 'due_on',
        'estimation' => 'estimation',
        'priority_id' => 'priority_id',
        'billable' => 'billable',
        'hidden_from_clients' => 'hidden_from_clients',
    ];

    /**
     * Apply a partial update. Each changed field goes through UpdateTask one at
     * a time — that is what broadcasts a per-property TaskUpdated event, which
     * is what open boards and task drawers listen for.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<int|string, UploadedFile>  $uploads
     * @param  array<int|string, int>  $stormAttachmentIds  STORM's id for an upload, under the same key
     */
    public function update(Task $task, array $changes, array $uploads = [], array $stormAttachmentIds = []): Task
    {
        return DB::transaction(function () use ($task, $changes, $uploads, $stormAttachmentIds) {
            foreach ($this->fields as $input => $field) {
                if (array_key_exists($input, $changes)) {
                    (new UpdateTask)->update($task, [$field => $changes[$input]]);
                }
            }

            // The group is the one field the payload states absolutely rather
            // than partially: STORM always sends task_group_id when the ticket
            // sits in a group and omits it when it does not — so a group moves
            // the task (through the move action, which rebuilds ordering and
            // broadcasts like a drag-and-drop), and no group at all unfiles
            // it, unless a ticket status stands in for one.
            if (($changes['task_group_id'] ?? null) !== null) {
                $task = (new MoveTaskToGroup)->move($task, TaskGroup::findOrFail($changes['task_group_id']));
            } elseif (! array_key_exists('storm_ticket_status', $changes)) {
                $task = $this->unfile($task);
            }

            // The status then picks the final column by name — Open and
            // On-going belong in "STORM", Closed in "Done" — overriding the
            // group id, which mostly restates where the task already was.
            // Ignored for an unfiled task, and it leaves the group alone
            // when the project has no such column.
            if (array_key_exists('storm_ticket_status', $changes) && $task->project_id !== null) {
                $group = StormTicketStatus::from($changes['storm_ticket_status'])->taskGroupIn($task->project_id);

                if ($group && $group->id !== $task->group_id) {
                    $task = (new MoveTaskToGroup)->move($task, $group);
                }
            }

            if (array_key_exists('completed', $changes)) {
                $task->update(['completed_at' => $changes['completed'] ? now() : null]);

                TaskUpdated::dispatch($task, 'completed_at');
            }

            // The work status carries completion — ignored for an unfiled
            // task, and it wins over `completed` when a payload carries both.
            if (array_key_exists('storm_ticket_work_status', $changes) && $task->project_id !== null) {
                $task = $this->applyWorkStatus($task, StormTicketWorkStatus::from($changes['storm_ticket_work_status']));
            }

            if (! empty($uploads)) {
                (new CreateTask)->uploadAttachments($task, $uploads, true, $stormAttachmentIds);
            }

            if (! empty($changes['remove_attachments'] ?? [])) {
                $this->removeStormAttachments($task, $changes['remove_attachments']);
            }

            return $task->refresh();
        });
    }

    /**
     * What a STORM work status does to the task: resolved and unfinished both
     * mean done, while open and ongoing put the task back in play. Unfinished
     * — closed without fixing it — also wears a "Blocked" label; any other
     * state takes it off again. Shared with the create path, which applies it
     * to tickets born already closed.
     */
    public function applyWorkStatus(Task $task, StormTicketWorkStatus $work): Task
    {
        // Keeps the original completion time when the task is already done.
        $task->update(['completed_at' => $work->completes() ? ($task->completed_at ?? now()) : null]);

        TaskUpdated::dispatch($task, 'completed_at');

        $this->syncBlockedLabel($task, $work === StormTicketWorkStatus::UNFINISHED);

        return $task->refresh();
    }

    /**
     * Put the "Blocked" label on or take it off, leaving every other label
     * alone. The label is created on first use; going through UpdateTask
     * broadcasts the change the way the drawer's label picker does.
     */
    protected function syncBlockedLabel(Task $task, bool $blocked): void
    {
        $label = $blocked
            ? Label::firstOrCreate(['name' => 'Blocked'], ['color' => 'red'])
            : Label::where('name', 'Blocked')->first();

        if (! $label) {
            return;
        }

        $labels = $task->labels()->pluck('labels.id');

        if ($labels->contains($label->id) === $blocked) {
            return;
        }

        $labels = $blocked
            ? $labels->push($label->id)
            : $labels->reject(fn ($id) => $id === $label->id);

        (new UpdateTask)->update($task, ['labels' => $labels->values()->all()]);
    }

    /**
     * Take the task off its project entirely — no project, no group, and no
     * number, since numbers run per project. The inverse of the filing that
     * MoveTaskToGroup does; it hands all three back if the task returns.
     */
    protected function unfile(Task $task): Task
    {
        if ($task->project_id === null && $task->group_id === null) {
            return $task;
        }

        $task->update(['project_id' => null, 'group_id' => null, 'number' => null]);

        return $task;
    }

    /**
     * Drop the files STORM removed from its ticket, by its ids for them. Ids
     * PMIS never linked are skipped, so a removal can be sent twice safely.
     *
     * @param  array<int, int>  $stormIds
     */
    protected function removeStormAttachments(Task $task, array $stormIds): void
    {
        $attachments = $task->attachments()->whereIn('storm_attachment_id', $stormIds)->get();

        foreach ($attachments as $attachment) {
            File::delete(public_path($attachment->path));

            if ($attachment->thumb) {
                File::delete(public_path($attachment->thumb));
            }

            $attachment->delete();

            // Same event the web app sends, so open drawers drop the file too.
            AttachmentDeleted::dispatch($task, $attachment->id);
        }
    }
}

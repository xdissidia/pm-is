<?php

namespace App\Actions\Task;

use App\Enums\StormTicketStatus;
use App\Events\Task\TaskUpdated;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

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

            // Goes through the move action so ordering is rebuilt and the board
            // gets the same TaskGroupChanged broadcast a drag-and-drop sends.
            if (array_key_exists('task_group_id', $changes)) {
                $task = (new MoveTaskToGroup)->move($task, TaskGroup::findOrFail($changes['task_group_id']));
            }

            // A STORM ticket status picks the group by name instead of by id:
            // Closed lands in "Done", anything still live goes to "STORM".
            if (array_key_exists('storm_ticket_status', $changes)) {
                $group = StormTicketStatus::from($changes['storm_ticket_status'])
                    ->taskGroupIn($task->project_id);

                if ($group) {
                    $task = (new MoveTaskToGroup)->move($task, $group);
                }
            }

            if (array_key_exists('completed', $changes)) {
                $task->update(['completed_at' => $changes['completed'] ? now() : null]);

                TaskUpdated::dispatch($task, 'completed_at');
            }

            if (! empty($uploads)) {
                (new CreateTask)->uploadAttachments($task, $uploads, true, $stormAttachmentIds);
            }

            return $task->refresh();
        });
    }
}

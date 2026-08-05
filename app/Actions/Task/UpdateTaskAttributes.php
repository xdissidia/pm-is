<?php

namespace App\Actions\Task;

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
     * @param  array<int, UploadedFile>  $uploads
     */
    public function update(Task $task, array $changes, array $uploads = []): Task
    {
        return DB::transaction(function () use ($task, $changes, $uploads) {
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

            if (array_key_exists('completed', $changes)) {
                $task->update(['completed_at' => $changes['completed'] ? now() : null]);

                TaskUpdated::dispatch($task, 'completed_at');
            }

            if (! empty($uploads)) {
                (new CreateTask)->uploadAttachments($task, $uploads);
            }

            return $task->refresh();
        });
    }
}

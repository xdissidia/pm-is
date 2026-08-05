<?php

namespace App\Actions\Task;

use App\Events\Task\TaskGroupChanged;
use App\Events\Task\TaskOrderChanged;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;

class MoveTaskToGroup
{
    /**
     * Move a task into a task group, optionally at a specific position.
     *
     * A null position appends the task to the end of the group. Moving a task
     * into the group it is already in is treated as a reorder.
     */
    public function move(Task $task, TaskGroup $group, ?int $position = null): Task
    {
        return DB::transaction(function () use ($task, $group, $position) {
            $fromGroupId = $task->group_id;
            $toGroupId = $group->id;
            $sameGroup = $fromGroupId === $toGroupId;

            $sourceIds = $this->orderedTaskIds($task->project_id, $fromGroupId);
            $fromIndex = (int) array_search($task->id, $sourceIds, true);

            $targetIds = $sameGroup ? $sourceIds : $this->orderedTaskIds($task->project_id, $toGroupId);
            $targetIds = array_values(array_diff($targetIds, [$task->id]));

            $toIndex = $position === null ? count($targetIds) : min($position, count($targetIds));

            array_splice($targetIds, $toIndex, 0, [$task->id]);

            if (! $sameGroup) {
                $task->update(['group_id' => $toGroupId]);
            }

            Task::setNewOrder($targetIds);

            $sameGroup
                ? TaskOrderChanged::dispatch($task->project_id, $toGroupId, $fromIndex, $toIndex)
                : TaskGroupChanged::dispatch($task->project_id, $fromGroupId, $toGroupId, $fromIndex, $toIndex);

            return $task->refresh();
        });
    }

    /**
     * Ids of the group's tasks as the board shows them — the model's global
     * scopes already order by `order_column` and drop archived tasks.
     */
    protected function orderedTaskIds(int $projectId, int $groupId): array
    {
        return Task::where('project_id', $projectId)
            ->where('group_id', $groupId)
            ->pluck('id')
            ->all();
    }
}

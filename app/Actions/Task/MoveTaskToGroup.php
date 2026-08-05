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
     *
     * A task stored unfiled has no project or group of its own; it adopts the
     * destination group's project here, and only then gets its task number.
     */
    public function move(Task $task, TaskGroup $group, ?int $position = null): Task
    {
        return DB::transaction(function () use ($task, $group, $position) {
            $fromGroupId = $task->group_id;
            $toGroupId = $group->id;
            $sameGroup = $fromGroupId === $toGroupId;
            $unfiled = $task->project_id === null;
            $projectId = $task->project_id ?? $group->project_id;

            $sourceIds = $fromGroupId === null ? [] : $this->orderedTaskIds($projectId, $fromGroupId);
            $fromIndex = (int) array_search($task->id, $sourceIds, true);

            $targetIds = $sameGroup ? $sourceIds : $this->orderedTaskIds($projectId, $toGroupId);
            $targetIds = array_values(array_diff($targetIds, [$task->id]));

            $toIndex = $position === null ? count($targetIds) : min($position, count($targetIds));

            array_splice($targetIds, $toIndex, 0, [$task->id]);

            $attributes = $sameGroup ? [] : ['group_id' => $toGroupId];

            if ($unfiled) {
                // Counted before the task itself joins the project.
                $attributes['number'] = Task::withArchived()->where('project_id', $projectId)->count() + 1;
                $attributes['project_id'] = $projectId;
            }

            if ($attributes !== []) {
                $task->update($attributes);
            }

            Task::setNewOrder($targetIds);

            $sameGroup
                ? TaskOrderChanged::dispatch($projectId, $toGroupId, $fromIndex, $toIndex)
                : TaskGroupChanged::dispatch($projectId, $fromGroupId, $toGroupId, $fromIndex, $toIndex);

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

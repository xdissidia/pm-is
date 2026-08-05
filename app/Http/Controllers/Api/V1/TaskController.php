<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Task\CreateTask;
use App\Actions\Task\MoveTaskToGroup;
use App\Actions\Task\UpdateTaskAttributes;
use App\Enums\StormTicketWorkStatus;
use App\Events\Task\TaskUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Task\CompleteTaskRequest;
use App\Http\Requests\Api\Task\MoveTaskRequest;
use App\Http\Requests\Api\Task\StoreTaskRequest;
use App\Http\Requests\Api\Task\UpdateTaskRequest;
use App\Http\Resources\Task\TaskResource;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Support\StormSync;
use Illuminate\Http\JsonResponse;

class TaskController extends Controller
{
    /**
     * Create a task. `task_group_id` decides where it lands; the project is
     * taken from that group, so callers never pass it.
     *
     * Without a group the task is stored unfiled: no project, no group, no
     * number. It gets all three when an update moves it into a task group
     * (see MoveTaskToGroup).
     */
    public function store(StoreTaskRequest $request): JsonResponse
    {
        $taskGroup = TaskGroup::find($request->validated('task_group_id'));

        $project = $taskGroup ? $this->projectOf($taskGroup) : null;

        $this->authorize('create', [Task::class, $project]);

        abort_if(
            $project?->isArchived() === true,
            422,
            'Tasks cannot be added to an archived project.',
        );

        $task = (new CreateTask)->create($project, [
            'group_id' => $taskGroup?->id,
            'storm_ticket_id' => $request->validated('storm_ticket_id'),
            'name' => $request->validated('title'),
            'description' => $request->validated('body'),
            'due_on' => null,
            'estimation' => null,
            'priority_id' => null,
            'pricing_type' => null,
            'fixed_price' => null,
            'hidden_from_clients' => false,
            'billable' => true,
            'assigned_users' => $request->validated('assignees', []),
            'subscribed_users' => $request->validated('subscribers', []),
            'attachments' => $request->uploads(),
            // STORM keys its upload parts by its own attachment id.
            'attachment_storm_ids' => $request->uploadStormIds(),
        ]);

        // A ticket can arrive already closed — its work status says so. Same
        // rule as on update: ignored for an unfiled task, and never echoed
        // back to STORM, since this request came from there.
        $work = $request->validated('storm_ticket_work_status');

        if ($work !== null && $task->project_id !== null) {
            $task = StormSync::withoutSyncing(
                fn () => (new UpdateTaskAttributes)->applyWorkStatus($task, StormTicketWorkStatus::from($work)),
            );
        }

        return response()->json([
            'data' => new TaskResource($task->loadDefault()->load('taskGroup:id,name')),
        ], 201);
    }

    /**
     * Partially update a task — only the fields present in the body change,
     * with one exception: the payload states the task group absolutely.
     * Sending task_group_id (any project's, for a STORM-linked task) moves
     * the task there; omitting it unfiles the task — no project, no group.
     */
    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $project = $this->projectOf($task);

        $this->authorize('update', [$task, $project]);

        $changes = $request->changes();

        // Moving groups and completing are separate permissions in the web app;
        // going through this endpoint must not be a way around them. Every
        // update is a move of sorts here — the group is stated absolutely, so
        // omitting it unfiles the task (see UpdateTaskAttributes) — and it has
        // to be allowed where the task lands: the destination group's project,
        // or the current one when the payload names no group.
        $destination = $this->projectOfGroup($changes['task_group_id'] ?? null) ?? $project;

        if ($destination !== null) {
            $this->authorize('reorder', [Task::class, $destination]);
        }

        if (array_key_exists('completed', $changes) || array_key_exists('storm_ticket_work_status', $changes)) {
            $this->authorize('complete', [Task::class, $project]);
        }

        // Not pushed back to STORM: this request came *from* STORM, so it
        // already has these values (see App\Support\StormSync).
        $task = StormSync::withoutSyncing(
            fn () => (new UpdateTaskAttributes)->update($task, $changes, $request->uploads(), $request->uploadStormIds()),
        );

        return $this->respondWithTask($task);
    }

    /**
     * Move a task into another task group (optionally at a given position).
     */
    public function move(MoveTaskRequest $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('reorder', [Task::class, $project]);

        $this->ensureTaskBelongsToProject($project, $task);

        $group = TaskGroup::findOrFail($request->validated('task_group_id'));

        $task = StormSync::withoutSyncing(
            fn () => (new MoveTaskToGroup)->move($task, $group, $request->validated('position')),
        );

        return $this->respondWithTask($task);
    }

    /**
     * Mark a task as done, or reopen it with `{"completed": false}`.
     */
    public function complete(CompleteTaskRequest $request, Project $project, Task $task): JsonResponse
    {
        $this->authorize('complete', [Task::class, $project]);

        $this->ensureTaskBelongsToProject($project, $task);

        StormSync::withoutSyncing(function () use ($request, $task) {
            $task->update(['completed_at' => $request->shouldComplete() ? now() : null]);

            TaskUpdated::dispatch($task, 'completed_at');
        });

        return $this->respondWithTask($task);
    }

    /**
     * The `task` route binding is explicit (see RouteServiceProvider), so
     * scopeBindings() does not apply — verify the parent/child link by hand.
     */
    protected function ensureTaskBelongsToProject(Project $project, Task $task): void
    {
        abort_if($task->project_id !== $project->id, 404, 'Task not found in this project.');
    }

    /**
     * The owning project, archived ones included — Project's global scope hides
     * those, and policies still need the record to answer with. Null only for a
     * task stored unfiled (see store()).
     */
    protected function projectOf(Task|TaskGroup $model): ?Project
    {
        return $model->project()->withArchived()->first();
    }

    /**
     * The project a task group id belongs to, for authorizing a move before the
     * task itself has one.
     */
    protected function projectOfGroup(?int $groupId): ?Project
    {
        $group = $groupId === null ? null : TaskGroup::find($groupId);

        return $group ? $this->projectOf($group) : null;
    }

    protected function respondWithTask(Task $task): JsonResponse
    {
        return response()->json([
            'data' => new TaskResource($task->loadDefault()->load('taskGroup:id,name')),
        ]);
    }
}

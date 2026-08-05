<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Task\CreateTask;
use App\Actions\Task\MoveTaskToGroup;
use App\Actions\Task\UpdateTaskAttributes;
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
     * Create a task in the given task group. The project is taken from the
     * group, so callers never pass it.
     */
    public function store(StoreTaskRequest $request, TaskGroup $taskGroup): JsonResponse
    {
        $project = $this->projectOf($taskGroup);

        $this->authorize('create', [Task::class, $project]);

        abort_if(
            $project->isArchived() || $taskGroup->isArchived(),
            422,
            'Tasks cannot be added to an archived project or task group.',
        );

        $task = (new CreateTask)->create($project, [
            'group_id' => $taskGroup->id,
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

        return response()->json([
            'data' => new TaskResource($task->loadDefault()->load('taskGroup:id,name')),
        ], 201);
    }

    /**
     * Partially update a task — only the fields present in the body change.
     * The project is taken from the task, so callers never pass it.
     */
    public function update(UpdateTaskRequest $request, Task $task): JsonResponse
    {
        $project = $this->projectOf($task);

        $this->authorize('update', [$task, $project]);

        $changes = $request->changes();

        // Moving groups and completing are separate permissions in the web app;
        // going through this endpoint must not be a way around them.
        if (array_key_exists('task_group_id', $changes) || array_key_exists('storm_ticket_status', $changes)) {
            $this->authorize('reorder', [Task::class, $project]);
        }

        if (array_key_exists('completed', $changes)) {
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
     * those, and policies still need the record to answer with.
     */
    protected function projectOf(Task|TaskGroup $model): Project
    {
        return $model->project()->withArchived()->firstOrFail();
    }

    protected function respondWithTask(Task $task): JsonResponse
    {
        return response()->json([
            'data' => new TaskResource($task->loadDefault()->load('taskGroup:id,name')),
        ]);
    }
}

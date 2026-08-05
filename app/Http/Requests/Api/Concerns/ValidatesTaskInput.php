<?php

namespace App\Http\Requests\Api\Concerns;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Services\PermissionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ValidatesTaskInput
{
    /**
     * The project this request operates on. Endpoints that still take a
     * {project} segment use it; the rest infer it from the task or group,
     * which is the only reason a project is needed at all here.
     */
    protected function project(): ?Project
    {
        $project = $this->route('project');

        if ($project instanceof Project) {
            return $project;
        }

        $owner = $this->route('task') ?? $this->route('taskGroup');

        if ($owner instanceof Task || $owner instanceof TaskGroup) {
            return $owner->project()->withArchived()->first();
        }

        return null;
    }

    /**
     * A task group can only be one that lives in this project and is not archived.
     */
    protected function taskGroupInProjectRule(): Exists
    {
        return Rule::exists('task_groups', 'id')
            ->where('project_id', $this->project()?->id)
            ->whereNull('archived_at');
    }

    /**
     * A task that is not in the project 404s whatever the body says, so this
     * has to run before any rule can blame a field for the mismatch.
     */
    protected function abortIfTaskIsNotInProject(): void
    {
        $project = $this->route('project');
        $task = $this->route('task');

        abort_if(
            $project instanceof Project && $task instanceof Task && $task->project_id !== $project->id,
            404,
            'Task not found in this project.',
        );
    }

    /**
     * Assignees and subscribers must be able to see the project they are put
     * on — otherwise they get notified about a task they cannot open.
     */
    protected function validateMembersHaveProjectAccess(Validator $validator): void
    {
        $project = $this->project();

        if (! $project instanceof Project || $validator->errors()->isNotEmpty()) {
            return;
        }

        $allowed = PermissionService::usersWithAccessToProject($project)->pluck('id');

        foreach (['assignees', 'subscribers'] as $field) {
            foreach ((array) $this->input($field, []) as $index => $id) {
                if (! $allowed->contains((int) $id)) {
                    $validator->errors()->add(
                        "$field.$index",
                        "The user with ID $id does not have access to this project.",
                    );
                }
            }
        }
    }
}

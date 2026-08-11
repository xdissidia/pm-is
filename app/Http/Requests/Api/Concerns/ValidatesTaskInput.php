<?php

namespace App\Http\Requests\Api\Concerns;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ValidatesTaskInput
{
    /**
     * The project this request operates on. Endpoints that still take a
     * {project} segment use it; the rest infer it from the task or group,
     * which is the only reason a project is needed at all here.
     *
     * A create falls back to the group named in the body, and a task that is
     * not filed anywhere yet has no project until one is — both end up null
     * when the request names no group at all.
     */
    protected function project(): ?Project
    {
        $project = $this->route('project');

        if ($project instanceof Project) {
            return $project;
        }

        $owner = $this->route('task') ?? $this->route('taskGroup');

        if ($owner instanceof Task || $owner instanceof TaskGroup) {
            $project = $owner->project()->withArchived()->first();
        }

        return $project ?? $this->projectOfGroupInBody();
    }

    /**
     * The project of the task group the body asks for. Read straight off the
     * input: the rules that validate the id run against this too.
     */
    protected function projectOfGroupInBody(): ?Project
    {
        $groupId = $this->input('task_group_id');

        if (! is_numeric($groupId)) {
            return null;
        }

        return TaskGroup::find((int) $groupId)?->project()->withArchived()->first();
    }

    /**
     * A task group can only be one that lives in this project and is not
     * archived. Two exceptions take any live group: a task with no project
     * yet, and a STORM-linked task — its identity is the ticket, not the
     * project, so STORM may refile it anywhere (the move restamps the
     * project, see MoveTaskToGroup).
     */
    protected function taskGroupInProjectRule(): Exists
    {
        $rule = Rule::exists('task_groups', 'id')->whereNull('archived_at');

        $task = $this->route('task');

        if ($task instanceof Task && filled($task->storm_ticket_id)) {
            return $rule;
        }

        $project = $this->project();

        return $project ? $rule->where('project_id', $project->id) : $rule;
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
     * Assignees and subscribers arrive as employee numbers — the identifier
     * STORM knows users by, so it never has to learn PMIS user ids.
     *
     * @return array<int, mixed>
     */
    protected function memberItemRules(): array
    {
        return ['string', 'max:50', 'distinct', Rule::exists('users', 'employee_number')];
    }

    /**
     * The user ids behind the employee numbers a field carries. Null when the
     * field was not sent at all — an absent list is not an empty one.
     *
     * @return array<int, int>|null
     */
    public function memberIds(string $field): ?array
    {
        if (! array_key_exists($field, $this->validated())) {
            return null;
        }

        $numbers = $this->validated($field) ?? [];

        return User::whereIn('employee_number', $numbers)->pluck('id')->all();
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

        $sent = collect(['assignees', 'subscribers'])
            ->flatMap(fn (string $field) => (array) $this->input($field, []))
            ->filter();

        // Matched case-insensitively, like the exists rule under the usual
        // MySQL collation.
        $idsByNumber = User::whereIn('employee_number', $sent->all())
            ->pluck('id', 'employee_number')
            ->mapWithKeys(fn (int $id, string $number) => [Str::lower($number) => $id]);

        foreach (['assignees', 'subscribers'] as $field) {
            foreach ((array) $this->input($field, []) as $index => $number) {
                $id = $idsByNumber->get(Str::lower((string) $number));

                if (! $allowed->contains($id)) {
                    $validator->errors()->add(
                        "$field.$index",
                        "The user with employee number $number does not have access to this project.",
                    );
                }
            }
        }
    }
}

<?php

namespace App\Http\Requests\Api\Task;

use App\Enums\StormTicketStatus;
use App\Http\Requests\Api\Concerns\ValidatesTaskInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    use ValidatesTaskInput;

    /**
     * Fields this endpoint understands. Anything absent is left untouched.
     */
    public const UPDATABLE = [
        'title',
        'body',
        'assignees',
        'subscribers',
        'task_group_id',
        'storm_ticket_status',
        'completed',
        'labels',
        'due_on',
        'estimation',
        'priority_id',
        'billable',
        'hidden_from_clients',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept any casing or spacing for the ticket status ("on going",
     * "ONGOING") by folding it to the canonical value before the rules run.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('storm_ticket_status')) {
            return;
        }

        $status = StormTicketStatus::fromLoose($this->input('storm_ticket_status'));

        if ($status) {
            $this->merge(['storm_ticket_status' => $status->value]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string'],
            'uploads' => ['sometimes', 'array', 'max:20'],
            'uploads.*' => ['file', 'max:25600'],
            'assignees' => ['sometimes', 'array'],
            'assignees.*' => ['integer', 'distinct', 'exists:users,id'],
            'subscribers' => ['sometimes', 'array'],
            'subscribers.*' => ['integer', 'distinct', 'exists:users,id'],
            'task_group_id' => ['sometimes', 'integer', $this->taskGroupInProjectRule()],
            'storm_ticket_status' => ['sometimes', 'string', Rule::enum(StormTicketStatus::class)],
            'completed' => ['sometimes', 'boolean'],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['integer', 'distinct', 'exists:labels,id'],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'estimation' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'priority_id' => ['sometimes', 'nullable', 'integer', 'exists:task_priorities,id'],
            'billable' => ['sometimes', 'boolean'],
            'hidden_from_clients' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateMembersHaveProjectAccess($validator);

            $touched = array_intersect(array_keys($this->all()), [...self::UPDATABLE, 'uploads']);

            if (empty($touched)) {
                $validator->errors()->add(
                    'title',
                    'Send at least one field to update: '.implode(', ', [...self::UPDATABLE, 'uploads']).'.',
                );
            }

            $this->validateStormTicketStatus($validator);
        });
    }

    /**
     * The ticket status decides the task group, so it cannot be combined with
     * an explicit one, and the group it maps to has to actually exist.
     */
    protected function validateStormTicketStatus(Validator $validator): void
    {
        if (! $this->has('storm_ticket_status') || $validator->errors()->has('storm_ticket_status')) {
            return;
        }

        if ($this->has('task_group_id')) {
            $validator->errors()->add(
                'storm_ticket_status',
                'Send either storm_ticket_status or task_group_id, not both — each one decides the task group.',
            );

            return;
        }

        $status = StormTicketStatus::tryFrom((string) $this->input('storm_ticket_status'));
        $project = $this->project();

        if (! $status || ! $project) {
            return;
        }

        if (! $status->taskGroupIn($project->id)) {
            $validator->errors()->add(
                'storm_ticket_status',
                "This project has no \"{$status->taskGroupName()}\" task group to move the task into.",
            );
        }
    }

    /**
     * Only the fields actually present in the request, so an absent key is
     * never confused with an explicit null.
     */
    public function changes(): array
    {
        return array_intersect_key($this->validated(), array_flip(self::UPDATABLE));
    }

    public function messages(): array
    {
        return [
            'task_group_id.exists' => 'The selected task group does not exist in this project, or is archived.',
        ];
    }

    public function attributes(): array
    {
        return [
            'uploads.*' => 'upload',
            'assignees.*' => 'assignee',
            'subscribers.*' => 'subscriber',
            'labels.*' => 'label',
        ];
    }
}

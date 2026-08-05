<?php

namespace App\Http\Requests\Api\Task;

use App\Enums\StormTicketStatus;
use App\Enums\StormTicketWorkStatus;
use App\Http\Requests\Api\Concerns\ReadsStormUploads;
use App\Http\Requests\Api\Concerns\ValidatesTaskInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    use ReadsStormUploads, ValidatesTaskInput;

    /**
     * Fields this endpoint understands. Anything absent is left untouched —
     * except the task group, which the payload states absolutely (omitting it
     * unfiles the task, see UpdateTaskAttributes).
     */
    public const UPDATABLE = [
        'title',
        'body',
        'assignees',
        'subscribers',
        'task_group_id',
        'storm_ticket_status',
        'storm_ticket_work_status',
        'completed',
        'labels',
        'due_on',
        'estimation',
        'priority_id',
        'billable',
        'hidden_from_clients',
        'remove_attachments',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept any casing or spacing for the two statuses ("on going",
     * "Closed (resolved)") by folding them to canonical values before the
     * rules run.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('storm_ticket_status') && ($status = StormTicketStatus::fromLoose($this->input('storm_ticket_status')))) {
            $this->merge(['storm_ticket_status' => $status->value]);
        }

        if ($this->has('storm_ticket_work_status') && ($work = StormTicketWorkStatus::fromLoose($this->input('storm_ticket_work_status')))) {
            $this->merge(['storm_ticket_work_status' => $work->value]);
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
            // STORM's own ids for files it dropped off the ticket — the same
            // ids its uploads arrive under (see ReadsStormUploads). Unknown
            // ids are skipped, so a re-sent removal cannot fail.
            'remove_attachments' => ['sometimes', 'array'],
            'remove_attachments.*' => ['integer', 'distinct'],
            'assignees' => ['sometimes', 'array'],
            'assignees.*' => ['integer', 'distinct', 'exists:users,id'],
            'subscribers' => ['sometimes', 'array'],
            'subscribers.*' => ['integer', 'distinct', 'exists:users,id'],
            // Omitting it (or sending null) unfiles the task — STORM states
            // the ticket's group absolutely on every update.
            'task_group_id' => ['sometimes', 'nullable', 'integer', $this->taskGroupInProjectRule()],
            // Both are ignored for an unfiled task (see UpdateTaskAttributes).
            // The group id places the task first; the status then picks the
            // final column by name — "STORM" while live, "Done" once closed.
            'storm_ticket_status' => ['sometimes', 'string', Rule::enum(StormTicketStatus::class)],
            'storm_ticket_work_status' => ['sometimes', 'string', Rule::enum(StormTicketWorkStatus::class)],
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
        });
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

<?php

namespace App\Http\Requests\Api\Task;

use App\Http\Requests\Api\Concerns\ValidatesTaskInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

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

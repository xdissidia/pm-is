<?php

namespace App\Http\Requests\Api\Task;

use App\Http\Requests\Api\Concerns\ValidatesTaskInput;
use Illuminate\Foundation\Http\FormRequest;

class MoveTaskRequest extends FormRequest
{
    use ValidatesTaskInput;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->abortIfTaskIsNotInProject();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'task_group_id' => ['required', 'integer', $this->taskGroupInProjectRule()],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'task_group_id.exists' => 'The selected task group does not exist in this project, or is archived.',
        ];
    }
}

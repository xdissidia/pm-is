<?php

namespace App\Http\Requests\Api\Task;

use App\Http\Requests\Api\Concerns\ValidatesTaskInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
{
    use ValidatesTaskInput;

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
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            // STORM stamps the ticket it is creating the task for, so PMIS does
            // not file that same ticket back (see FileStormTicket).
            'storm_ticket_id' => ['nullable', 'integer'],
            'uploads' => ['nullable', 'array', 'max:20'],
            'uploads.*' => ['file', 'max:25600'],
            'subscribers' => ['nullable', 'array'],
            'subscribers.*' => ['integer', 'distinct', 'exists:users,id'],
            'assignees' => ['nullable', 'array'],
            'assignees.*' => ['integer', 'distinct', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateMembersHaveProjectAccess($validator));
    }

    public function attributes(): array
    {
        return [
            'title' => 'title',
            'body' => 'body',
            'uploads.*' => 'upload',
            'assignees.*' => 'assignee',
            'subscribers.*' => 'subscriber',
        ];
    }
}

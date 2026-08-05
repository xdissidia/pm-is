<?php

namespace App\Http\Requests\Api\Task;

use Illuminate\Foundation\Http\FormRequest;

class CompleteTaskRequest extends FormRequest
{
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
            'completed' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Omitting `completed` marks the task done — the common case for this endpoint.
     */
    public function shouldComplete(): bool
    {
        return $this->validated('completed') ?? true;
    }
}

<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateAuthUserRequest extends FormRequest
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
            'job_title' => 'required|string',
            'employee_number' => ['nullable', 'string', 'max:50', Rule::unique('users')->ignore(auth()->id())],
            'name' => 'required|string',
            'phone' => 'string|nullable',
            'email' => ['required', 'email:rfc,dns', Rule::unique('users')->ignore(auth()->id())],
            'password' => 'nullable|min:8|confirmed',
            'avatar' => [File::image(), 'nullable'],
            'default_project_tag_ids' => 'array',
            'default_project_tag_ids.*' => 'integer|exists:project_tags,id',
        ];
    }
}

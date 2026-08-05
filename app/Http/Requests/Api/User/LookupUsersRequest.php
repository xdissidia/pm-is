<?php

namespace App\Http\Requests\Api\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LookupUsersRequest extends FormRequest
{
    /**
     * Guards against someone dumping the whole user table one page at a time.
     */
    public const MAX_EMAILS = 200;

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
            'emails' => ['required', 'array', 'min:1', 'max:'.self::MAX_EMAILS],
            'emails.*' => ['required', 'string', 'email'],
        ];
    }

    /**
     * The requested addresses, trimmed and de-duplicated case-insensitively,
     * in the order they were sent.
     */
    public function emails(): Collection
    {
        return collect($this->validated('emails'))
            ->map(fn (string $email) => trim($email))
            ->unique(fn (string $email) => Str::lower($email))
            ->values();
    }

    public function attributes(): array
    {
        return [
            'emails.*' => 'email address',
        ];
    }
}

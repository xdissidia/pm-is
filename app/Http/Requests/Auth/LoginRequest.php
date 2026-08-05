<?php

namespace App\Http\Requests\Auth;

use App\Actions\User\CreateUser;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if ($this->ssoAuthenticate()) {
            return;
        }

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Attempt to authenticate thru PAGASA SSO
     */
    public function ssoAuthenticate()
    {
        $http = Http::withoutVerifying()->post('https://sso.pagasa.co/api/v1/auth', [
            'username' => $this->email,
            'password' => $this->password,
        ]);

        if ($http->successful()) {
            $response = $http->json();
            if ($response['auth'] == true) {
                $user = User::where('active_directory_guid', $response['data']['guid'])->first();
                if (! $user) {
                    $user = User::where('email', $response['data']['email'])->first();
                    if ($user) {
                        $user->update(
                            [
                                'active_directory_guid' => $response['data']['guid'],
                                // 'password' => Hash::make($this->password),
                            ]
                        );
                    } else {
                        if ($response['data']['email'] == null) {
                            throw ValidationException::withMessages([
                                'email' => 'SSO authentication failed: Email not provided, please contact your VDI Administrator Local #1502.',
                            ]);
                        }
                        $user = (new CreateUser)->create([
                            'active_directory_guid' => $response['data']['guid'],
                            'name' => $response['data']['name'],
                            'job_title' => $response['data']['position'],
                            'phone' => null,
                            'rate' => null,
                            'email' => $response['data']['email'],
                            'password' => $this->password,
                            'avatar' => null,
                            'roles' => ['Team Member'],
                        ]);
                    }
                }
                Auth::login($user);

                return true;
            }
        }

        return false;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => 'Too many login attempts. Please try again later.',
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->input('email')).'|'.$this->ip());
    }
}

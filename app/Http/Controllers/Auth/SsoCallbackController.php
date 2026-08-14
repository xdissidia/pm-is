<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SsoCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('sso_state');

        if (! is_string($state) || $state === '' || $request->query('state') !== $state) {
            Log::warning('sso.callback: state mismatch', [
                'has_session_state' => is_string($state) && $state !== '',
                'has_query_state' => $request->query('state') !== null,
            ]);

            abort(403, 'Invalid SSO state.');
        }

        try {
            $response = Http::asForm()->post(config('services.sso.url').'/api/sso/token', [
                'client_id' => config('services.sso.client_id'),
                'client_secret' => config('services.sso.client_secret'),
                'code' => $request->query('code'),
            ]);
        } catch (ConnectionException $e) {
            Log::error('sso.callback: could not reach token endpoint', [
                'url' => config('services.sso.url').'/api/sso/token',
                'message' => $e->getMessage(),
            ]);

            abort(403, 'SSO sign-in failed.');
        }

        if (! $response->successful()) {
            Log::warning('sso.callback: token exchange failed', [
                'status' => $response->status(),
                'error' => $response->json('error'),
                'error_description' => $response->json('error_description'),
            ]);

            abort(403, 'SSO sign-in failed.');
        }

        $profile = $response->json('user');

        if (empty($profile['employee_number'])) {
            Log::warning('sso.callback: profile has no employee_number', [
                'username' => $profile['username'] ?? null,
                'email' => $profile['email'] ?? null,
                'profile_keys' => is_array($profile) ? array_keys($profile) : gettype($profile),
                'response_keys' => array_keys($response->json() ?? []),
            ]);

            return $this->rejectToLogin();
        }

        $user = User::withArchived()
            ->where('employee_number', $profile['employee_number'])
            ->first();

        if ($user?->archived_at !== null) {
            Log::warning('sso.callback: matched user is archived', [
                'user_id' => $user->id,
                'employee_number' => $profile['employee_number'],
            ]);

            return $this->rejectToLogin();
        }

        if ($user) {
            $user->update([
                'name' => $profile['name'],
                'email' => $profile['email'],
            ]);
        } else {
            // Accounts predating employee numbers must be linked by an administrator first.
            if (User::withArchived()->where('email', $profile['email'])->exists()) {
                Log::warning('sso.callback: email taken by unlinked account, admin must link it', [
                    'email' => $profile['email'],
                    'employee_number' => $profile['employee_number'],
                ]);

                return $this->rejectToLogin();
            }

            Log::info('sso.callback: provisioning new user', [
                'employee_number' => $profile['employee_number'],
                'email' => $profile['email'],
            ]);

            $user = User::create([
                'employee_number' => $profile['employee_number'],
                'name' => $profile['name'],
                'email' => $profile['email'],
                'password' => Str::random(40),
            ]);

            $user->assignRole('Team Member');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        Log::info('sso.callback: login successful', [
            'user_id' => $user->id,
            'employee_number' => $user->employee_number,
        ]);

        return redirect()->intended('/');
    }

    protected function rejectToLogin(): RedirectResponse
    {
        return redirect()
            ->route('auth.login.form')
            ->with('notify', 'sso-employee-number-required');
    }
}

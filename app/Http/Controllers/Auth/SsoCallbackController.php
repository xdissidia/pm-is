<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SsoCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('sso_state');

        abort_unless(
            is_string($state) && $state !== '' && $request->query('state') === $state,
            403,
            'Invalid SSO state.'
        );

        $response = Http::asForm()->post(config('services.sso.url').'/api/sso/token', [
            'client_id' => config('services.sso.client_id'),
            'client_secret' => config('services.sso.client_secret'),
            'code' => $request->query('code'),
        ]);

        abort_unless($response->successful(), 403, 'SSO sign-in failed.');

        $profile = $response->json('user');

        if (empty($profile['employee_number'])) {
            return $this->rejectToLogin();
        }

        $user = User::withArchived()
            ->where('employee_number', $profile['employee_number'])
            ->first();

        if ($user?->archived_at !== null) {
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
                return $this->rejectToLogin();
            }

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

        return redirect()->intended('/');
    }

    protected function rejectToLogin(): RedirectResponse
    {
        return redirect()
            ->route('auth.login.form')
            ->with('notify', 'sso-employee-number-required');
    }
}

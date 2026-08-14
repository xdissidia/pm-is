<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

class SsoCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(
            $request->query('state') === $request->session()->pull('sso_state'),
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

        $user = User::updateOrCreate(
            ['employee_number' => $profile['employee_number']],
            [
                'name' => $profile['name'],
                'email' => $profile['email'],
            ],
        );

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
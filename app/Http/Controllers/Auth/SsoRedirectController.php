<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SsoRedirectController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('sso_state', $state);

        return redirect()->away(config('services.sso.url').'/sso/authorize?'.http_build_query([
            'client_id' => config('services.sso.client_id'),
            'redirect_uri' => route('sso.callback'),
            'state' => $state,
            'prompt' => 'login',   // forces the SSO to re-check credentials / 2FA
        ]));
    }
}

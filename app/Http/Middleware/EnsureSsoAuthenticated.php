<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureSsoAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return $next($request);
        }

        $state = Str::random(40);
        $request->session()->put('sso_state', $state);

        $query = http_build_query([
            'client_id' => config('services.sso.client_id'),
            'redirect_uri' => route('sso.callback'),
            'state' => $state,
        ]);

        return redirect()->away(config('services.sso.url').'/sso/authorize?'.$query);
    }
}
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireEmployeeNumber
{
    /**
     * Routes that stay reachable while the employee number is missing.
     *
     * @var array<int, string>
     */
    protected array $except = [
        'account.employee-number.edit',
        'account.employee-number.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user
            && $user->employee_number === null
            && ! $user->hasRole('client')
            && ! $request->routeIs(...$this->except)) {
            return redirect()->route('account.employee-number.edit');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\User\LookupUsersRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserController extends Controller
{
    /**
     * Resolve a batch of email addresses to user ids.
     *
     * POST rather than GET on purpose: email addresses are personal data and do
     * not belong in a URL, and the list can be long enough to blow a query string.
     */
    public function lookup(LookupUsersRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $emails = $request->emails();

        // Match on LOWER() so behaviour does not depend on the database
        // collation. Archived users are excluded by the model's global scope.
        $found = User::whereIn(
            DB::raw('LOWER(email)'),
            $emails->map(fn (string $email) => Str::lower($email))->all(),
        )
            ->orderBy('name')
            ->get(['id', 'email', 'name']);

        $foundByEmail = $found->keyBy(fn (User $user) => Str::lower($user->email));

        $notFound = $emails
            ->reject(fn (string $email) => $foundByEmail->has(Str::lower($email)))
            ->values();

        return response()->json([
            'data' => $found->map->only(['id', 'email', 'name'])->values(),
            'meta' => [
                'requested' => $emails->count(),
                'found' => $found->count(),
                'not_found' => $notFound,
            ],
        ]);
    }
}

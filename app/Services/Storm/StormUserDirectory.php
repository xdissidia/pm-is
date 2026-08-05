<?php

namespace App\Services\Storm;

use App\Http\Requests\Api\User\LookupUsersRequest;
use App\Models\User;
use App\Models\UserStorm;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Resolves PMIS users to the accounts STORM knows them by
 * (POST /api/v1/pmis/users/lookup), and records the answer in `user_storms`.
 *
 * The mirror image of PMIS's own Api\V1\UserController::lookup, which is how
 * STORM resolves addresses to PMIS users.
 */
class StormUserDirectory extends StormClient
{
    /**
     * Ask STORM which of its accounts these addresses belong to. Addresses are
     * trimmed and de-duplicated, and sent in batches of 200 — the same cap
     * both sides put on the endpoint.
     *
     * @param  iterable<string>  $emails
     * @return Collection<int, array{id: int, name: ?string, email: ?string, pmis_user_id: ?int}>
     *
     * @throws StormApiException
     */
    public function lookup(iterable $emails): Collection
    {
        $emails = collect($emails)
            ->filter()
            ->map(fn (string $email) => trim($email))
            ->unique(fn (string $email) => Str::lower($email))
            ->values();

        if ($emails->isEmpty()) {
            return collect();
        }

        return $emails
            ->chunk(LookupUsersRequest::MAX_EMAILS)
            ->flatMap(function (Collection $chunk) {
                $body = $this->send('post', $this->url('users/lookup'), ['emails' => $chunk->values()->all()]);

                // Anything that is not an account with an id is not something
                // to resolve against — better skipped than fatal inside a job.
                return collect($body['data'] ?? [])
                    ->filter(fn ($account) => is_array($account) && filled($account['id'] ?? null));
            })
            ->values();
    }

    /**
     * Resolve PMIS users to one STORM user id each, recording it on the user.
     * Users STORM does not know are simply absent from the result.
     *
     * @param  iterable<User>  $users
     * @return array<int, int> PMIS user id => STORM user id
     *
     * @throws StormApiException
     */
    public function resolve(iterable $users): array
    {
        $users = collect($users)
            ->filter(fn (User $user) => filled($user->email))
            ->unique('id');

        if ($users->isEmpty()) {
            return [];
        }

        $accounts = $this->lookup($users->pluck('email'))
            ->groupBy(fn (array $account) => Str::lower((string) ($account['email'] ?? '')));

        $resolved = [];

        foreach ($users as $user) {
            $matches = $accounts->get(Str::lower($user->email), collect());

            if ($matches->isEmpty()) {
                continue;
            }

            $account = $this->preferred($user, $matches);

            $this->remember($user, $account);

            $resolved[$user->id] = (int) $account['id'];
        }

        return $resolved;
    }

    /**
     * Which account to use when STORM returns several for one address: the one
     * already linked to this PMIS user, otherwise the oldest.
     *
     * @param  Collection<int, array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    protected function preferred(User $user, Collection $accounts): array
    {
        return $accounts->firstWhere('pmis_user_id', $user->id)
            ?? $accounts->sortBy('id')->first();
    }

    /**
     * One row per user: a user who turns up under a different STORM account
     * than last time has their row moved over rather than doubled up.
     *
     * @param  array<string, mixed>  $account
     */
    protected function remember(User $user, array $account): void
    {
        UserStorm::updateOrCreate(
            ['user_id' => $user->id],
            [
                'storm_user_id' => (int) $account['id'],
                'name' => $account['name'] ?? null,
                'email' => $account['email'] ?? $user->email,
                'pmis_user_id' => $account['pmis_user_id'] ?? null,
            ],
        );
    }
}

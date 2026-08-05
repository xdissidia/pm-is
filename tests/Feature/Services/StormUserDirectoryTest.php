<?php

use App\Models\User;
use App\Models\UserStorm;
use App\Services\Storm\StormApiException;
use App\Services\Storm\StormUserDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.storm.url' => 'http://localhost:9000',
        'services.storm.token' => 'test-token',
    ]);

    $this->directory = new StormUserDirectory;

    $this->admin = User::factory()->create(['email' => 'admin@pagasa.ict', 'name' => 'Admin']);
    $this->metss = User::factory()->create(['email' => 'metss@pagasa.ict', 'name' => 'METTSS']);
});

/**
 * The shape STORM's lookup answers with — note two accounts on one address.
 */
function stormAccounts(): array
{
    return [
        'data' => [
            ['id' => 1, 'name' => 'Admin', 'email' => 'admin@pagasa.ict', 'pmis_user_id' => 777],
            ['id' => 2, 'name' => 'METTSS', 'email' => 'metss@pagasa.ict', 'pmis_user_id' => null],
            ['id' => 25, 'name' => 'METTSS', 'email' => 'metss@pagasa.ict', 'pmis_user_id' => null],
        ],
        'meta' => ['requested' => 3, 'found' => 2, 'accounts' => 3, 'not_found' => ['nobody@pagasa.test']],
    ];
}

it('looks addresses up on storm', function () {
    Http::fake(['localhost:9000/*' => Http::response(stormAccounts())]);

    $accounts = $this->directory->lookup(['admin@pagasa.ict', ' METSS@pagasa.ict ', 'admin@pagasa.ict']);

    expect($accounts)->toHaveCount(3);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://localhost:9000/api/v1/pmis/users/lookup'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            // Trimmed, and de-duplicated regardless of casing.
            && $request['emails'] === ['admin@pagasa.ict', 'METSS@pagasa.ict'];
    });
});

it('records the account storm reports against the pmis user', function () {
    Http::fake(['localhost:9000/*' => Http::response(stormAccounts())]);

    $this->directory->resolve([$this->admin, $this->metss]);

    // One row each, even though STORM knows METTSS under two accounts.
    expect(UserStorm::count())->toBe(2)
        ->and($this->metss->storm->storm_user_id)->toBe(2);

    $account = $this->admin->storm;

    expect($account->storm_user_id)->toBe(1)
        ->and($account->name)->toBe('Admin')
        ->and($account->email)->toBe('admin@pagasa.ict')
        // What STORM has on file, which is not this PMIS user's id.
        ->and($account->pmis_user_id)->toBe(777);
});

it('moves the row when storm answers with a different account', function () {
    // A sequence, so the second lookup answers differently from the first.
    Http::fakeSequence('localhost:9000/*')
        ->push(stormAccounts())
        ->push(['data' => [
            ['id' => 25, 'name' => 'METTSS', 'email' => 'metss@pagasa.ict', 'pmis_user_id' => $this->metss->id],
        ]]);

    $this->directory->resolve([$this->metss]);
    $this->directory->resolve([$this->metss]);

    expect(UserStorm::where('user_id', $this->metss->id)->count())->toBe(1)
        ->and($this->metss->fresh()->storm->storm_user_id)->toBe(25);
});

it('resolves one storm id per user, preferring the linked account', function () {
    Http::fake(['localhost:9000/*' => Http::response([
        'data' => [
            ['id' => 2, 'name' => 'METTSS', 'email' => 'metss@pagasa.ict', 'pmis_user_id' => null],
            ['id' => 25, 'name' => 'METTSS', 'email' => 'metss@pagasa.ict', 'pmis_user_id' => $this->metss->id],
        ],
    ])]);

    expect($this->directory->resolve([$this->metss]))->toBe([$this->metss->id => 25]);
});

it('falls back to the oldest account when none is linked', function () {
    Http::fake(['localhost:9000/*' => Http::response(stormAccounts())]);

    expect($this->directory->resolve([$this->metss]))->toBe([$this->metss->id => 2]);
});

it('leaves out users storm does not know', function () {
    Http::fake(['localhost:9000/*' => Http::response(['data' => []])]);

    expect($this->directory->resolve([$this->admin]))->toBe([])
        ->and(UserStorm::count())->toBe(0);
});

it('refreshes what it already recorded', function () {
    UserStorm::create([
        'user_id' => $this->admin->id,
        'storm_user_id' => 1,
        'name' => 'Old name',
        'email' => 'admin@pagasa.ict',
        'pmis_user_id' => null,
    ]);

    Http::fake(['localhost:9000/*' => Http::response(stormAccounts())]);

    $this->directory->resolve([$this->admin]);

    expect(UserStorm::where('user_id', $this->admin->id)->count())->toBe(1)
        ->and($this->admin->fresh()->storm->name)->toBe('Admin');
});

it('sends nothing when there is nobody to look up', function () {
    Http::fake();

    expect($this->directory->resolve([]))->toBe([])
        ->and($this->directory->lookup([]))->toBeEmpty();

    Http::assertNothingSent();
});

it('reports a lookup storm rejected', function () {
    Http::fake(['localhost:9000/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    expect(fn () => $this->directory->resolve([$this->admin]))
        ->toThrow(StormApiException::class, 'Unauthenticated.');
});

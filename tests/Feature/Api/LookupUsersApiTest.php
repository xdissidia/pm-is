<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $this->seed(\Database\Seeders\PermissionSeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('admin');

    $this->url = '/api/v1/users/lookup';
});

it('resolves emails to user ids', function () {
    $first = User::factory()->create(['email' => 'alvin@pagasa.test', 'name' => 'Alvin']);
    $second = User::factory()->create(['email' => 'grace@pagasa.test', 'name' => 'Grace']);

    $response = $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['emails' => ['alvin@pagasa.test', 'grace@pagasa.test']])
        ->assertOk()
        ->assertJsonPath('meta.requested', 2)
        ->assertJsonPath('meta.found', 2)
        ->assertJsonPath('meta.not_found', []);

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$first->id, $second->id]);
});

it('reports emails it could not match', function () {
    User::factory()->create(['email' => 'known@pagasa.test']);

    $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['emails' => ['known@pagasa.test', 'ghost@pagasa.test']])
        ->assertOk()
        ->assertJsonPath('meta.found', 1)
        ->assertJsonPath('meta.not_found', ['ghost@pagasa.test'])
        ->assertJsonCount(1, 'data');
});

it('matches regardless of case and surrounding whitespace', function () {
    $user = User::factory()->create(['email' => 'Mixed.Case@pagasa.test']);

    $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['emails' => ['  MIXED.case@PAGASA.test  ']])
        ->assertOk()
        ->assertJsonPath('meta.not_found', [])
        ->assertJsonPath('data.0.id', $user->id);
});

it('de-duplicates the requested addresses', function () {
    User::factory()->create(['email' => 'twice@pagasa.test']);

    $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['emails' => ['twice@pagasa.test', 'TWICE@pagasa.test']])
        ->assertOk()
        ->assertJsonPath('meta.requested', 1)
        ->assertJsonPath('meta.found', 1)
        ->assertJsonCount(1, 'data');
});

it('does not resolve archived users', function () {
    $archived = User::factory()->create(['email' => 'gone@pagasa.test']);
    $archived->archive();

    $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['emails' => ['gone@pagasa.test']])
        ->assertOk()
        ->assertJsonPath('meta.found', 0)
        ->assertJsonPath('meta.not_found', ['gone@pagasa.test']);
});

it('requires at least one email', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['emails' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('emails');
});

it('rejects malformed addresses', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['emails' => ['fine@pagasa.test', 'not-an-email']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('emails.1');
});

it('caps the batch size', function () {
    $emails = collect(range(1, 201))->map(fn ($i) => "user{$i}@pagasa.test")->all();

    $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['emails' => $emails])
        ->assertStatus(422)
        ->assertJsonValidationErrors('emails');
});

it('denies users without the view users permission', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $this->actingAs($developer, 'sanctum')
        ->postJson($this->url, ['emails' => ['anyone@pagasa.test']])
        ->assertForbidden();
});

it('requires authentication', function () {
    $this->postJson($this->url, ['emails' => ['anyone@pagasa.test']])
        ->assertUnauthorized();
});

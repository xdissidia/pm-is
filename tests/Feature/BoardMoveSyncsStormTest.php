<?php

use App\Enums\StormTicketStatus;
use App\Models\ClientCompany;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // PermissionService memoizes project access statically, and RefreshDatabase
    // recycles ids between tests — drop the cache so tests stay isolated.
    (function () {
        static::$usersWithAccessToProject = [];
    })->call(new PermissionService);

    $this->seed(\Database\Seeders\CountrySeeder::class);
    $this->seed(\Database\Seeders\CurrencySeeder::class);
    $this->seed(\Database\Seeders\RoleSeeder::class);
    $this->seed(\Database\Seeders\PermissionSeeder::class);

    config([
        'services.storm.url' => 'http://localhost:9000',
        'services.storm.token' => 'test-token',
    ]);

    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $this->project = Project::create([
        'client_company_id' => ClientCompany::factory()->create()->id,
        'name' => 'Test Project',
        'hourly_rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->stormGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => StormTicketStatus::LIVE_GROUP,
    ]);

    $this->doneGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => StormTicketStatus::DONE_GROUP,
    ]);

    Http::fake(['localhost:9000/*' => Http::response(['data' => ['id' => 77]])]);
});

it('syncs the storm ticket when its task is dragged to another column', function () {
    $task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->stormGroup->id,
        'created_by_user_id' => $this->user->id,
        'storm_ticket_id' => 77,
        'name' => 'Radar is down',
        'number' => 1,
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    // What the board sends after dragging the task into the Done column.
    $this->post(route('projects.tasks.move', $this->project), [
        'ids' => [$task->id],
        'from_group_id' => $this->stormGroup->id,
        'to_group_id' => $this->doneGroup->id,
        'from_index' => 0,
        'to_index' => 0,
    ])->assertOk();

    expect($task->refresh()->group_id)->toBe($this->doneGroup->id);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request['status'] === StormTicketStatus::CLOSED->value);
});

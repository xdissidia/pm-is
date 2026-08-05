<?php

use App\Models\ClientCompany;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    $this->seed(\Database\Seeders\TaskPrioritySeeder::class);

    $this->user = User::factory()->create();
    $this->user->assignRole('admin');

    // Observers on Project/Task log activity against the authenticated user.
    $this->actingAs($this->user);

    $this->project = Project::create([
        'client_company_id' => ClientCompany::factory()->create()->id,
        'name' => 'Test Project',
        'hourly_rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->todo = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'Todo', 'color' => 'blue']);
    $this->done = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'Done', 'color' => 'green']);

    $this->makeTask = function (TaskGroup $group, string $name) {
        return Task::create([
            'project_id' => $this->project->id,
            'group_id' => $group->id,
            'created_by_user_id' => $this->user->id,
            'name' => $name,
            'number' => Task::where('project_id', $this->project->id)->count() + 1,
            'hidden_from_clients' => false,
            'billable' => true,
        ]);
    };

    $this->task = ($this->makeTask)($this->todo, 'Move me');
});

function groupOrder(int $groupId): array
{
    return Task::where('group_id', $groupId)->pluck('name')->all();
}

it('moves a task to another group, appending it by default', function () {
    ($this->makeTask)($this->done, 'Already done A');
    ($this->makeTask)($this->done, 'Already done B');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/group", [
            'task_group_id' => $this->done->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.group.id', $this->done->id)
        ->assertJsonPath('data.group.name', 'Done');

    expect($this->task->fresh()->group_id)->toBe($this->done->id)
        ->and(groupOrder($this->done->id))->toBe(['Already done A', 'Already done B', 'Move me'])
        ->and(groupOrder($this->todo->id))->toBe([]);
});

it('honours an explicit position', function () {
    ($this->makeTask)($this->done, 'Already done A');
    ($this->makeTask)($this->done, 'Already done B');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/group", [
            'task_group_id' => $this->done->id,
            'position' => 1,
        ])
        ->assertOk();

    expect(groupOrder($this->done->id))->toBe(['Already done A', 'Move me', 'Already done B']);
});

it('clamps a position beyond the end of the group', function () {
    ($this->makeTask)($this->done, 'Already done A');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/group", [
            'task_group_id' => $this->done->id,
            'position' => 99,
        ])
        ->assertOk();

    expect(groupOrder($this->done->id))->toBe(['Already done A', 'Move me']);
});

it('reorders within the same group', function () {
    ($this->makeTask)($this->todo, 'Second');
    ($this->makeTask)($this->todo, 'Third');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/group", [
            'task_group_id' => $this->todo->id,
            'position' => 2,
        ])
        ->assertOk();

    expect(groupOrder($this->todo->id))->toBe(['Second', 'Third', 'Move me']);
});

it('rejects a task group from another project', function () {
    $otherProject = Project::create([
        'client_company_id' => ClientCompany::factory()->create()->id,
        'name' => 'Other Project',
        'hourly_rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);
    $otherGroup = TaskGroup::create(['project_id' => $otherProject->id, 'name' => 'Elsewhere', 'color' => 'red']);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/group", [
            'task_group_id' => $otherGroup->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('task_group_id');

    expect($this->task->fresh()->group_id)->toBe($this->todo->id);
});

it('rejects a task that belongs to another project', function () {
    $otherProject = Project::create([
        'client_company_id' => ClientCompany::factory()->create()->id,
        'name' => 'Other Project',
        'hourly_rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$otherProject->id}/tasks/{$this->task->id}/group", [
            'task_group_id' => $this->done->id,
        ])
        ->assertNotFound();
});

it('marks a task as done', function () {
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.id', $this->task->id);

    expect($this->task->fresh()->completed_at)->not->toBeNull();
});

it('reopens a task with completed false', function () {
    $this->task->update(['completed_at' => now()]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/complete", ['completed' => false])
        ->assertOk()
        ->assertJsonPath('data.completed_at', null);

    expect($this->task->fresh()->completed_at)->toBeNull();
});

it('logs completing a task in the activity feed', function () {
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/complete")
        ->assertOk();

    expect($this->task->activities()->where('title', 'Task was completed')->exists())->toBeTrue();
});

it('validates the completed flag', function () {
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/complete", ['completed' => 'maybe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('completed');
});

it('requires authentication on both endpoints', function () {
    auth()->guard('web')->logout();

    $this->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/group", [
        'task_group_id' => $this->done->id,
    ])->assertUnauthorized();

    $this->putJson("/api/v1/projects/{$this->project->id}/tasks/{$this->task->id}/complete")
        ->assertUnauthorized();
});

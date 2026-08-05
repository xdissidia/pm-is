<?php

use App\Models\ClientCompany;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

    $this->group = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'STORM', 'color' => 'blue']);

    $this->url = '/api/v1/tasks';
});

it('stores a task with no project or task group', function () {
    Storage::fake('local');

    $response = $this
        ->actingAs($this->user, 'sanctum')
        ->post($this->url, [
            'title' => 'Ticket nobody has filed yet',
            'body' => '<p>Reported over the phone</p>',
            'storm_ticket_id' => 4242,
            'uploads' => [UploadedFile::fake()->create('report.pdf', 12)],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Ticket nobody has filed yet')
        ->assertJsonPath('data.storm_ticket_id', 4242)
        ->assertJsonPath('data.project.id', null)
        ->assertJsonPath('data.group.id', null)
        ->assertJsonPath('data.number', null)
        ->assertJsonPath('data.attachments.0.name', 'report.pdf');

    $task = Task::firstWhere('name', 'Ticket nobody has filed yet');

    expect($task->project_id)->toBeNull()
        ->and($task->group_id)->toBeNull()
        ->and($task->number)->toBeNull()
        ->and($task->created_by_user_id)->toBe($this->user->id);
});

it('still requires a title', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson($this->url, ['body' => 'no title here'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');
});

it('files an unfiled task into a project when it is moved into a task group', function () {
    // A task already in the project, so the number handed out is not the first.
    Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->group->id,
        'created_by_user_id' => $this->user->id,
        'name' => 'Existing task',
        'number' => 1,
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $task = Task::create([
        'created_by_user_id' => $this->user->id,
        'name' => 'Unfiled ticket',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson("/api/v1/tasks/{$task->id}", ['task_group_id' => $this->group->id])
        ->assertOk()
        ->assertJsonPath('data.project.id', $this->project->id)
        ->assertJsonPath('data.group.id', $this->group->id)
        ->assertJsonPath('data.number', 2);

    $task->refresh();

    expect($task->project_id)->toBe($this->project->id)
        ->and($task->group_id)->toBe($this->group->id)
        ->and($task->number)->toBe(2);
});

it('rejects a storm ticket status on a task that is not in a project yet', function () {
    $task = Task::create([
        'created_by_user_id' => $this->user->id,
        'name' => 'Unfiled ticket',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson("/api/v1/tasks/{$task->id}", ['storm_ticket_status' => 'Closed'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('storm_ticket_status');
});

it('updates an unfiled task without filing it', function () {
    $task = Task::create([
        'created_by_user_id' => $this->user->id,
        'name' => 'Unfiled ticket',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Renamed while unfiled'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed while unfiled')
        ->assertJsonPath('data.project.id', null);

    expect($task->fresh()->project_id)->toBeNull();
});

it('requires authentication', function () {
    auth()->guard('web')->logout();

    $this->postJson($this->url, ['title' => 'Nope'])->assertUnauthorized();
});

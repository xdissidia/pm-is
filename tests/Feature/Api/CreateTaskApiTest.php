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

    $clientCompany = ClientCompany::factory()->create();

    $this->project = Project::create([
        'client_company_id' => $clientCompany->id,
        'name' => 'Test Project',
        'hourly_rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->taskGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'To Do',
        'color' => 'blue',
    ]);

    $this->url = "/api/v1/task-groups/{$this->taskGroup->id}/tasks";
});

it('creates a task with title, body, subscribers, assignees and uploads', function () {
    Storage::fake('local');

    $assignee = User::factory()->create();
    $assignee->assignRole('admin');
    $subscriber = User::factory()->create();
    $subscriber->assignRole('admin');

    $response = $this
        ->actingAs($this->user, 'sanctum')
        ->post($this->url, [
            'title' => 'Task created over the API',
            'body' => '<p>Some rich text body</p>',
            'assignees' => [$assignee->id],
            'subscribers' => [$subscriber->id],
            'uploads' => [UploadedFile::fake()->create('spec.pdf', 12)],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Task created over the API')
        ->assertJsonPath('data.body', '<p>Some rich text body</p>')
        ->assertJsonPath('data.group.id', $this->taskGroup->id)
        ->assertJsonPath('data.project.id', $this->project->id)
        ->assertJsonPath('data.assignees.0.id', $assignee->id)
        ->assertJsonPath('data.attachments.0.name', 'spec.pdf');

    $task = Task::firstWhere('name', 'Task created over the API');

    expect($task->group_id)->toBe($this->taskGroup->id)
        ->and($task->created_by_user_id)->toBe($this->user->id)
        ->and($task->assignees->pluck('id')->all())->toBe([$assignee->id])
        ->and($task->subscribedUsers->pluck('id')->all())->toBe([$subscriber->id])
        ->and($task->attachments)->toHaveCount(1);
});

it('requires a title', function () {
    $this->actingAs($this->user, 'sanctum')
        ->post($this->url, ['body' => 'no title here'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');
});

it('rejects users without access to the project', function () {
    $outsider = User::factory()->create();
    $outsider->assignRole('client');

    $this->actingAs($this->user, 'sanctum')
        ->post($this->url, ['title' => 'Nope', 'assignees' => [$outsider->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('assignees.0');
});

it('requires authentication', function () {
    auth()->guard('web')->logout();

    $this->post($this->url, ['title' => 'Nope'])->assertUnauthorized();
});

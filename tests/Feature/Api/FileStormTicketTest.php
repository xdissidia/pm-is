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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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

    $this->otherGroup = TaskGroup::create([
        'project_id' => $this->project->id,
        'name' => 'To Do',
    ]);

    $this->createTask = fn (TaskGroup $group, array $payload = []) => $this
        ->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/tasks', array_merge([
            'task_group_id' => $group->id,
            'title' => 'Radar is down',
            'body' => '<p>No returns since 0300.</p>',
        ], $payload));
});

it('files a storm ticket for a task created in the storm group', function () {
    Http::fake(['localhost:9000/*' => Http::response(['data' => ['id' => 77]], 201)]);

    $response = ($this->createTask)($this->stormGroup)->assertCreated();

    $task = Task::findOrFail($response->json('data.id'));

    expect($task->storm_ticket_id)->toBe(77);

    Http::assertSent(function (Request $request) use ($task) {
        return $request->url() === 'http://localhost:9000/api/v1/pmis/tickets'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['title'] === 'Radar is down'
            && $request['body'] === '<p>No returns since 0300.</p>'
            && $request['status'] === StormTicketStatus::OPEN->value
            && $request['pmis_task_id'] === $task->id
            && $request['task_group_id'] === $this->stormGroup->id
            && $request['pmis_user_id'] === $this->user->id
            && $request['author_employee_number'] === $this->user->employee_number;
    });
});

it('attaches assignees by employee number, without a user lookup', function () {
    $assignee = User::factory()->create(['employee_number' => '240187']);
    $assignee->assignRole('admin');

    Http::fake(['localhost:9000/api/v1/pmis/tickets' => Http::response(['data' => ['id' => 99]], 201)]);

    ($this->createTask)($this->stormGroup, ['assignees' => [$assignee->id]])->assertCreated();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:9000/api/v1/pmis/tickets'
        // The employee number, not a PMIS or STORM user id.
        && $request['assignees'] === ['240187']
        && $request['pmis_user_id'] === $this->user->id
        && $request['author_employee_number'] === $this->user->employee_number);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'users/lookup'));
});

it('files the ticket unassigned when no assignee has an employee number', function () {
    $assignee = User::factory()->create(['employee_number' => null]);
    $assignee->assignRole('admin');

    Http::fake(['localhost:9000/api/v1/pmis/tickets' => Http::response(['data' => ['id' => 99]], 201)]);

    $response = ($this->createTask)($this->stormGroup, ['assignees' => [$assignee->id]])->assertCreated();

    expect(Task::findOrFail($response->json('data.id'))->storm_ticket_id)->toBe(99);

    Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:9000/api/v1/pmis/tickets'
        && ! isset($request['assignees']));
});

it('sends the task attachments up with the ticket', function () {
    Http::fake(['localhost:9000/*' => Http::response(['data' => ['id' => 88]], 201)]);

    // Not a faked disk: the ticket uploads read the stored file back off disk.
    $response = $this
        ->actingAs($this->user, 'sanctum')
        ->post('/api/v1/tasks', [
            'task_group_id' => $this->stormGroup->id,
            'title' => 'Radar QC report',
            'body' => '<p>Attached: the QC export.</p>',
            'uploads' => [UploadedFile::fake()->create('qc-export.pdf', 12)],
        ])
        ->assertCreated();

    Http::assertSent(function (Request $request) {
        $parts = collect($request->data());

        return $request->isMultipart()
            && $parts->firstWhere('name', 'uploads[]')['filename'] === 'qc-export.pdf'
            && $parts->firstWhere('name', 'title')['contents'] === 'Radar QC report';
    });

    File::deleteDirectory(storage_path('app/public/tasks/'.$response->json('data.id')));
});

it('leaves tasks in other groups alone', function () {
    Http::fake();

    ($this->createTask)($this->otherGroup)->assertCreated();

    Http::assertNothingSent();
});

it('does not file a ticket back for a task storm itself created', function () {
    Http::fake();

    $response = ($this->createTask)($this->stormGroup, ['storm_ticket_id' => 512])->assertCreated();

    expect(Task::findOrFail($response->json('data.id'))->storm_ticket_id)->toBe(512);

    Http::assertNothingSent();
});

it('stays quiet when no storm token is configured', function () {
    config(['services.storm.token' => null]);
    Http::fake();

    ($this->createTask)($this->stormGroup)->assertCreated();

    Http::assertNothingSent();
});

it('still creates the task when storm rejects the ticket', function () {
    Http::fake(['localhost:9000/*' => Http::response(['message' => 'Server Error'], 500)]);

    $response = ($this->createTask)($this->stormGroup)->assertCreated();

    expect(Task::findOrFail($response->json('data.id'))->storm_ticket_id)->toBeNull();
});

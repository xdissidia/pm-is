<?php

use App\Actions\Task\CreateTask;
use App\Actions\Task\MoveTaskToGroup;
use App\Actions\Task\UpdateTask;
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

    // A task already linked to ticket 77, as if STORM had filed it.
    $this->task = fn (array $attributes = []) => (new CreateTask)->create($this->project, array_merge([
        'group_id' => $this->stormGroup->id,
        'storm_ticket_id' => 77,
        'name' => 'Radar is down',
        'description' => '<p>No returns since 0300.</p>',
        'due_on' => null,
        'estimation' => null,
        'priority_id' => null,
        'pricing_type' => null,
        'fixed_price' => null,
        'hidden_from_clients' => false,
        'billable' => true,
    ], $attributes));

    Http::fake(['localhost:9000/*' => Http::response(['data' => ['id' => 77]])]);
});

it('pushes a renamed task onto its ticket', function () {
    $task = ($this->task)();

    (new UpdateTask)->update($task, ['name' => 'Radar is back up']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'http://localhost:9000/api/v1/pmis/tickets/77'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request['title'] === 'Radar is back up');
});

it('pushes an edited description as the ticket body', function () {
    (new UpdateTask)->update(($this->task)(), ['description' => '<p>Antenna motor replaced.</p>']);

    Http::assertSent(fn (Request $request) => $request['body'] === '<p>Antenna motor replaced.</p>');
});

it('closes the ticket when the task is completed, and reopens it', function () {
    $task = ($this->task)();

    (new UpdateTask)->update($task, ['completed_at' => now()]);

    Http::assertSent(fn (Request $request) => $request['status'] === StormTicketStatus::CLOSED->value);

    (new UpdateTask)->update($task->refresh(), ['completed_at' => null]);

    Http::assertSent(fn (Request $request) => $request['status'] === StormTicketStatus::OPEN->value);
});

it('follows the task between the storm and done columns', function () {
    $task = ($this->task)();

    (new MoveTaskToGroup)->move($task, $this->doneGroup);

    Http::assertSent(fn (Request $request) => $request['status'] === StormTicketStatus::CLOSED->value);

    (new MoveTaskToGroup)->move($task->refresh(), $this->stormGroup);

    Http::assertSent(fn (Request $request) => $request['status'] === StormTicketStatus::OPEN->value);
});

it('uploads attachments added after the ticket was filed', function () {
    $task = ($this->task)();

    (new CreateTask)->uploadAttachments($task, [UploadedFile::fake()->create('antenna.pdf', 12)]);

    Http::assertSent(function (Request $request) {
        $parts = collect($request->data())->pluck('contents', 'name');

        return $request->isMultipart()
            && $parts->has('uploads[]')
            // Files cannot ride a real PATCH, so it degrades to POST override.
            && $parts['_method'] === 'PATCH'
            && $request->url() === 'http://localhost:9000/api/v1/pmis/tickets/77';
    });
});

it('leaves tasks without a ticket alone', function () {
    // Outside the STORM column, so nothing files a ticket for it either.
    $plain = ($this->task)([
        'storm_ticket_id' => null,
        'group_id' => TaskGroup::create(['project_id' => $this->project->id, 'name' => 'To Do'])->id,
    ]);

    (new UpdateTask)->update($plain, ['name' => 'Just a PMIS task']);

    Http::assertNothingSent();
});

it('does not echo a change that came from storm', function () {
    $task = ($this->task)();

    $this->actingAs($this->user, 'sanctum')
        ->patchJson("/api/v1/tasks/{$task->id}", [
            'title' => 'Renamed by STORM',
            'storm_ticket_status' => 'Closed',
            'completed' => true,
        ])
        ->assertOk();

    expect($task->refresh()->name)->toBe('Renamed by STORM')
        ->and($task->group_id)->toBe($this->doneGroup->id);

    Http::assertNothingSent();
});

it('does not echo a move or a complete that came from storm', function () {
    $task = ($this->task)();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$task->id}/group", [
            'task_group_id' => $this->doneGroup->id,
        ])
        ->assertOk();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/projects/{$this->project->id}/tasks/{$task->id}/complete", ['completed' => true])
        ->assertOk();

    Http::assertNothingSent();
});

it('keeps the task when storm rejects the update', function () {
    Http::fake(['localhost:9000/*' => Http::response(['message' => 'Server Error'], 500)]);

    $task = ($this->task)();

    (new UpdateTask)->update($task, ['name' => 'Still renamed locally']);

    expect(Task::findOrFail($task->id)->name)->toBe('Still renamed locally');
});

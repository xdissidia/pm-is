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

    // Ticket 77, answering with the files the upload tests send it. Stubs
    // registered here win over later ones, so this is the single response.
    Http::fake(['localhost:9000/*' => Http::response(['data' => [
        'id' => 77,
        'attachments' => [
            ['id' => 33, 'name' => 'keep.txt'],
            ['id' => 34, 'name' => 'antenna.pdf'],
        ],
    ]])]);
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

it('pushes the new assignee list onto the ticket by employee number', function () {
    $assignee = User::factory()->create();

    (new UpdateTask)->update(($this->task)(), ['assignees' => [$assignee->id]]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'http://localhost:9000/api/v1/pmis/tickets/77'
        && $request['assignees'] === [$assignee->employee_number]);
});

it('pushes an emptied assignee list when everyone is removed', function () {
    $assignee = User::factory()->create();
    $task = ($this->task)();

    \App\Support\StormSync::withoutSyncing(fn () => (new UpdateTask)->update($task, ['assignees' => [$assignee->id]]));

    (new UpdateTask)->update($task->refresh(), ['assignees' => []]);

    Http::assertSent(fn (Request $request) => $request['assignees'] === []);
});

it('leaves assignees without an employee number out of the push', function () {
    $numbered = User::factory()->create();
    $unnumbered = User::factory()->create(['employee_number' => null]);

    (new UpdateTask)->update(($this->task)(), ['assignees' => [$numbered->id, $unnumbered->id]]);

    Http::assertSent(fn (Request $request) => $request['assignees'] === [$numbered->employee_number]);
});

it('says nothing when the assignee list is restated unchanged', function () {
    $assignee = User::factory()->create();
    $task = ($this->task)();

    \App\Support\StormSync::withoutSyncing(fn () => (new UpdateTask)->update($task, ['assignees' => [$assignee->id]]));

    Http::fake();

    (new UpdateTask)->update($task->refresh(), ['assignees' => [$assignee->id]]);

    Http::assertNothingSent();
});

it('does not echo an assignee change that came from storm', function () {
    $assignee = User::factory()->create();
    $assignee->assignRole('admin');
    $task = ($this->task)();

    $this->actingAs($this->user, 'sanctum')
        ->patchJson("/api/v1/tasks/{$task->id}", [
            'assignees' => [$assignee->employee_number],
            'task_group_id' => $this->stormGroup->id,
        ])
        ->assertOk();

    expect($task->refresh()->assignees->pluck('id')->all())->toBe([$assignee->id]);

    Http::assertNothingSent();
});

it('resolves the ticket when the task is completed, and puts it back to ongoing when reopened', function () {
    $task = ($this->task)();

    (new UpdateTask)->update($task, ['completed_at' => now()]);

    // Completion speaks through the work status alone — the status only ever
    // reports column moves, and this task has not moved.
    Http::assertSent(fn (Request $request) => $request['work_status'] === 'resolved'
        && ! isset($request['status']));

    (new UpdateTask)->update($task->refresh(), ['completed_at' => null]);

    // Un-completing puts the work back in play: ongoing, not resolved.
    Http::assertSent(fn (Request $request) => $request['work_status'] === 'ongoing'
        && ! isset($request['status']));
});

it('follows the task between the storm and done columns', function () {
    $task = ($this->task)();

    (new MoveTaskToGroup)->move($task, $this->doneGroup);

    // A bare move into Done closes the ticket but does not resolve it.
    Http::assertSent(fn (Request $request) => $request['status'] === StormTicketStatus::CLOSED->value
        && ! isset($request['work_status']));

    (new MoveTaskToGroup)->move($task->refresh(), $this->stormGroup);

    Http::assertSent(fn (Request $request) => $request['status'] === StormTicketStatus::OPEN->value);
});

it('marks the ticket on-going when the task moves into a working column', function () {
    $doing = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'In Progress']);

    (new MoveTaskToGroup)->move(($this->task)(), $doing);

    Http::assertSent(fn (Request $request) => $request['status'] === StormTicketStatus::ON_GOING->value
        && ! isset($request['work_status']));
});

it('reports a blocked task unfinished when it is completed', function () {
    $blocked = \App\Models\Label::create(['name' => 'Blocked', 'color' => 'red']);
    $task = ($this->task)();

    \App\Support\StormSync::withoutSyncing(fn () => (new UpdateTask)->update($task, ['labels' => [$blocked->id]]));

    (new UpdateTask)->update($task->refresh(), ['completed_at' => now()]);

    Http::assertSent(fn (Request $request) => $request['work_status'] === 'unfinished');
});

it('reports a done ticket unfinished when tagged blocked, and resolved when untagged', function () {
    $blocked = \App\Models\Label::create(['name' => 'Blocked', 'color' => 'red']);
    $task = ($this->task)();

    \App\Support\StormSync::withoutSyncing(fn () => $task->update(['completed_at' => now()]));

    (new UpdateTask)->update($task, ['labels' => [$blocked->id]]);

    Http::assertSent(fn (Request $request) => $request['work_status'] === 'unfinished');

    (new UpdateTask)->update($task->refresh(), ['labels' => []]);

    Http::assertSent(fn (Request $request) => $request['work_status'] === 'resolved');
});

it('says nothing about the blocked label on a task that is not done', function () {
    $blocked = \App\Models\Label::create(['name' => 'Blocked', 'color' => 'red']);

    Http::fake();

    $task = ($this->task)();

    (new UpdateTask)->update($task, ['labels' => [$blocked->id]]);
    (new UpdateTask)->update($task->refresh(), ['labels' => []]);

    Http::assertNothingSent();
});

it('says nothing to storm about other labels', function () {
    $urgent = \App\Models\Label::create(['name' => 'Urgent', 'color' => 'orange']);

    Http::fake();

    (new UpdateTask)->update(($this->task)(), ['labels' => [$urgent->id]]);

    Http::assertNothingSent();
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

it('records storm ids for uploads, and removes them when the file is deleted', function () {
    $task = ($this->task)();

    $attachments = (new CreateTask)->uploadAttachments($task, [
        UploadedFile::fake()->create('keep.txt', 1),
        UploadedFile::fake()->create('antenna.pdf', 12),
    ]);

    $antenna = $attachments->firstWhere('name', 'antenna.pdf');

    expect($attachments->firstWhere('name', 'keep.txt')->fresh()->storm_attachment_id)->toBe(33)
        ->and($antenna->fresh()->storm_attachment_id)->toBe(34);

    // Refreshed first: the id was stamped on the copy the sync job loaded, and
    // the deletion has to see it to tell STORM which file went.
    $antenna->refresh()->delete();

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'http://localhost:9000/api/v1/pmis/tickets/77'
        && $request['remove_attachments'] === [34]);

    File::deleteDirectory(storage_path("app/public/tasks/{$task->id}"));
});

it('says nothing about a file storm never took', function () {
    $task = ($this->task)();

    $attachment = $task->attachments()->create([
        'user_id' => $this->user->id,
        'name' => 'never-sent.txt',
        'path' => '/storage/tasks/nope.txt',
        'type' => 'text/plain',
        'size' => 10,
    ]);

    Http::fake();

    $attachment->delete();

    Http::assertNothingSent();
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
            'task_group_id' => $this->doneGroup->id,
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

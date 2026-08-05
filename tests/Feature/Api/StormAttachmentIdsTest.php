<?php

use App\Actions\Task\CreateTask;
use App\Models\Attachment;
use App\Models\ClientCompany;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // Nothing here should reach STORM: these are the requests coming *from* it.
    Http::fake();

    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $this->project = Project::create([
        'client_company_id' => ClientCompany::factory()->create()->id,
        'name' => 'Test Project',
        'hourly_rate' => 5000,
        'default_pricing_type' => 'hourly',
    ]);

    $this->group = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'STORM']);

    $this->cleanUp = fn (int $taskId) => File::deleteDirectory(storage_path("app/public/tasks/{$taskId}"));
});

it('keeps the storm attachment ids sent with a new task', function () {
    $response = $this
        ->actingAs($this->user, 'sanctum')
        ->post('/api/v1/tasks', [
            'task_group_id' => $this->group->id,
            'title' => 'Ticket with files',
            'body' => '<p>Two files.</p>',
            'storm_ticket_id' => 512,
            // STORM names its parts uploads[{attachment id}].
            'uploads' => [34 => UploadedFile::fake()->create('one.pdf', 2), 35 => UploadedFile::fake()->create('two.pdf', 2)],
        ])
        ->assertCreated();

    $task = Task::findOrFail($response->json('data.id'));

    expect($task->attachments->pluck('storm_attachment_id', 'name')->all())
        ->toBe(['one.pdf' => 34, 'two.pdf' => 35]);

    ($this->cleanUp)($task->id);
});

it('keeps the storm attachment ids sent with an update', function () {
    $task = (new CreateTask)->create($this->project, [
        'group_id' => $this->group->id,
        'storm_ticket_id' => 512,
        'name' => 'Ticket from STORM',
        'description' => null,
        'due_on' => null,
        'estimation' => null,
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->patch("/api/v1/tasks/{$task->id}", [
            'uploads' => [77 => UploadedFile::fake()->create('report.pdf', 2)],
        ])
        ->assertOk();

    expect(Attachment::where('task_id', $task->id)->sole()->storm_attachment_id)->toBe(77);

    ($this->cleanUp)($task->id);
});

it('removes the attachments storm dropped off the ticket', function () {
    $task = (new CreateTask)->create($this->project, [
        'group_id' => $this->group->id,
        'storm_ticket_id' => 512,
        'name' => 'Ticket from STORM',
        'description' => null,
        'due_on' => null,
        'estimation' => null,
        'hidden_from_clients' => false,
        'billable' => true,
        'attachments' => [
            34 => UploadedFile::fake()->create('gone.pdf', 2),
            35 => UploadedFile::fake()->create('kept.pdf', 2),
        ],
        'attachment_storm_ids' => [34 => 34, 35 => 35],
    ]);

    $gone = storage_path('app/public'.str_replace('/storage', '', $task->attachments->firstWhere('name', 'gone.pdf')->path));

    expect(file_exists($gone))->toBeTrue();

    $this->actingAs($this->user, 'sanctum')
        ->patchJson("/api/v1/tasks/{$task->id}", [
            // 99 was never linked — a stale removal must not fail the request.
            'remove_attachments' => [34, 99],
        ])
        ->assertOk();

    expect(Attachment::where('task_id', $task->id)->pluck('name')->all())->toBe(['kept.pdf'])
        ->and(file_exists($gone))->toBeFalse();

    // The deletion came *from* STORM, so it must not be echoed back.
    Http::assertNothingSent();

    ($this->cleanUp)($task->id);
});

it('does not mistake a plain upload list for storm ids', function () {
    $response = $this
        ->actingAs($this->user, 'sanctum')
        ->post('/api/v1/tasks', [
            'task_group_id' => $this->group->id,
            'title' => 'Ticket with files',
            'body' => '<p>Sent as uploads[].</p>',
            'storm_ticket_id' => 512,
            'uploads' => [UploadedFile::fake()->create('one.pdf', 2), UploadedFile::fake()->create('two.pdf', 2)],
        ])
        ->assertCreated();

    $task = Task::findOrFail($response->json('data.id'));

    expect($task->attachments->pluck('storm_attachment_id')->filter()->all())->toBe([]);

    ($this->cleanUp)($task->id);
});

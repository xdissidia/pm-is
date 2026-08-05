<?php

use App\Models\ClientCompany;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\TaskPriority;
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

    $this->group = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'Todo', 'color' => 'blue']);
    $this->doneGroup = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'Done', 'color' => 'green']);

    $this->task = Task::create([
        'project_id' => $this->project->id,
        'group_id' => $this->group->id,
        'created_by_user_id' => $this->user->id,
        'name' => 'Original title',
        'number' => 1,
        'description' => '<p>Original body</p>',
        'hidden_from_clients' => false,
        'billable' => true,
    ]);

    $this->url = "/api/v1/tasks/{$this->task->id}";
});

it('updates title and body', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, [
            'title' => 'Renamed task',
            'body' => '<p>Rewritten body</p>',
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Renamed task')
        ->assertJsonPath('data.body', '<p>Rewritten body</p>');

    expect($this->task->fresh()->name)->toBe('Renamed task')
        ->and($this->task->fresh()->description)->toBe('<p>Rewritten body</p>');
});

it('leaves absent fields untouched', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['title' => 'Only the title changes'])
        ->assertOk();

    expect($this->task->fresh()->description)->toBe('<p>Original body</p>')
        ->and($this->task->fresh()->billable)->toBeTrue();
});

it('replaces assignees and subscribers wholesale', function () {
    $first = User::factory()->create();
    $first->assignRole('admin');
    $second = User::factory()->create();
    $second->assignRole('admin');

    $this->task->assignees()->sync([$first->id]);
    $this->task->subscribedUsers()->sync([$first->id]);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, [
            'assignees' => [$second->id],
            'subscribers' => [$second->id],
        ])
        ->assertOk()
        ->assertJsonPath('data.assignees.0.id', $second->id);

    expect($this->task->fresh()->assignees->pluck('id')->all())->toBe([$second->id])
        ->and($this->task->fresh()->subscribedUsers->pluck('id')->all())->toBe([$second->id]);
});

it('clears assignees with an empty array', function () {
    $other = User::factory()->create();
    $other->assignRole('admin');
    $this->task->assignees()->sync([$other->id]);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['assignees' => []])
        ->assertOk()
        ->assertJsonPath('data.assignees', []);

    expect($this->task->fresh()->assignees)->toHaveCount(0);
});

it('updates due date, estimation and priority', function () {
    $priority = TaskPriority::where('label', 'High')->first();

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, [
            'due_on' => '2026-09-01',
            'estimation' => 4.5,
            'priority_id' => $priority->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.due_on', '2026-09-01')
        ->assertJsonPath('data.priority.id', $priority->id);

    expect((float) $this->task->fresh()->estimation)->toBe(4.5);
});

it('nulls out a nullable field when sent explicitly', function () {
    $this->task->update(['due_on' => '2026-09-01']);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['due_on' => null])
        ->assertOk()
        ->assertJsonPath('data.due_on', null);

    expect($this->task->fresh()->due_on)->toBeNull();
});

it('syncs labels', function () {
    $label = Label::create(['name' => 'Urgent', 'color' => 'red']);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['labels' => [$label->id]])
        ->assertOk()
        ->assertJsonPath('data.labels.0.name', 'Urgent');
});

it('appends uploads without dropping existing attachments', function () {
    Storage::fake('local');

    $this->actingAs($this->user, 'sanctum')
        ->post($this->url, [
            '_method' => 'PATCH',
            'uploads' => [UploadedFile::fake()->create('first.pdf', 10)],
        ])
        ->assertOk();

    $this->actingAs($this->user, 'sanctum')
        ->post($this->url, [
            '_method' => 'PATCH',
            'title' => 'Now with two files',
            'uploads' => [UploadedFile::fake()->create('second.pdf', 10)],
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Now with two files');

    expect($this->task->fresh()->attachments->pluck('name')->all())->toBe(['first.pdf', 'second.pdf']);
});

it('moves the task to another group', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['task_group_id' => $this->doneGroup->id])
        ->assertOk()
        ->assertJsonPath('data.group.id', $this->doneGroup->id)
        ->assertJsonPath('data.group.name', 'Done');

    expect($this->task->fresh()->group_id)->toBe($this->doneGroup->id);
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
        ->patchJson($this->url, ['task_group_id' => $otherGroup->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('task_group_id');

    expect($this->task->fresh()->group_id)->toBe($this->group->id);
});

it('routes a closed STORM ticket into the Done group', function () {
    $storm = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'STORM', 'color' => 'red']);
    $this->task->update(['group_id' => $storm->id]);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['storm_ticket_status' => 'Closed', 'completed' => true])
        ->assertOk()
        ->assertJsonPath('data.group.name', 'Done');

    expect($this->task->fresh()->group_id)->toBe($this->doneGroup->id)
        ->and($this->task->fresh()->completed_at)->not->toBeNull();
});

it('routes open and on-going STORM tickets into the STORM group', function ($status) {
    $storm = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'STORM', 'color' => 'red']);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['storm_ticket_status' => $status])
        ->assertOk()
        ->assertJsonPath('data.group.name', 'STORM');

    expect($this->task->fresh()->group_id)->toBe($storm->id);
})->with(['Open', 'On-going']);

it('accepts a loosely cased ticket status', function () {
    $storm = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'STORM', 'color' => 'red']);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['storm_ticket_status' => 'ON GOING'])
        ->assertOk();

    expect($this->task->fresh()->group_id)->toBe($storm->id);
});

it('rejects an unknown ticket status', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['storm_ticket_status' => 'Escalated'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('storm_ticket_status');
});

it('rejects a ticket status sent together with a task group id', function () {
    TaskGroup::create(['project_id' => $this->project->id, 'name' => 'STORM', 'color' => 'red']);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, [
            'storm_ticket_status' => 'Open',
            'task_group_id' => $this->doneGroup->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('storm_ticket_status');

    expect($this->task->fresh()->group_id)->toBe($this->group->id);
});

it('rejects a ticket status when the project has no matching group', function () {
    // This project has "Done" but no "STORM" group.
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['storm_ticket_status' => 'Open'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('storm_ticket_status');

    expect($this->task->fresh()->group_id)->toBe($this->group->id);
});

it('marks an unfinished closed ticket via title and completed', function () {
    TaskGroup::create(['project_id' => $this->project->id, 'name' => 'STORM', 'color' => 'red']);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, [
            'title' => '[UNRESOLVED] group probe',
            'storm_ticket_status' => 'Closed',
            'completed' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.title', '[UNRESOLVED] group probe')
        ->assertJsonPath('data.group.name', 'Done');

    expect($this->task->fresh()->completed_at)->not->toBeNull();
});

it('reopens a ticket back into the STORM group', function () {
    $storm = TaskGroup::create(['project_id' => $this->project->id, 'name' => 'STORM', 'color' => 'red']);
    $this->task->update(['group_id' => $this->doneGroup->id, 'completed_at' => now()]);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['storm_ticket_status' => 'Open', 'completed' => false])
        ->assertOk()
        ->assertJsonPath('data.group.name', 'STORM')
        ->assertJsonPath('data.completed_at', null);

    expect($this->task->fresh()->group_id)->toBe($storm->id);
});

it('tags the task as done', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['completed' => true])
        ->assertOk();

    expect($this->task->fresh()->completed_at)->not->toBeNull();
});

it('reopens the task with completed false', function () {
    $this->task->update(['completed_at' => now()]);

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['completed' => false])
        ->assertOk()
        ->assertJsonPath('data.completed_at', null);

    expect($this->task->fresh()->completed_at)->toBeNull();
});

it('changes everything in one request', function () {
    Storage::fake('local');

    $assignee = User::factory()->create();
    $assignee->assignRole('admin');
    $subscriber = User::factory()->create();
    $subscriber->assignRole('admin');

    $this->actingAs($this->user, 'sanctum')
        ->post($this->url, [
            '_method' => 'PATCH',
            'title' => 'Everything at once',
            'body' => '<p>New body</p>',
            'assignees' => [$assignee->id],
            'subscribers' => [$subscriber->id],
            'task_group_id' => $this->doneGroup->id,
            'completed' => true,
            'uploads' => [UploadedFile::fake()->create('evidence.pdf', 10)],
        ])
        ->assertOk()
        ->assertJsonPath('data.title', 'Everything at once')
        ->assertJsonPath('data.group.id', $this->doneGroup->id)
        ->assertJsonPath('data.assignees.0.id', $assignee->id)
        ->assertJsonPath('data.attachments.0.name', 'evidence.pdf');

    $task = $this->task->fresh();

    expect($task->description)->toBe('<p>New body</p>')
        ->and($task->group_id)->toBe($this->doneGroup->id)
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->subscribedUsers->pluck('id')->all())->toBe([$subscriber->id]);
});

it('validates the completed flag', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['completed' => 'maybe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('completed');
});

it('logs the change in the activity feed', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['title' => 'Renamed for the audit trail'])
        ->assertOk();

    expect($this->task->activities()->where('title', 'Task name was changed')->exists())->toBeTrue();
});

it('rejects an empty body', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');
});

it('rejects a blank title', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['title' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('title');

    expect($this->task->fresh()->name)->toBe('Original title');
});

it('rejects an assignee without project access', function () {
    $outsider = User::factory()->create();
    $outsider->assignRole('client');

    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['assignees' => [$outsider->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('assignees.0');
});

it('rejects an unknown priority', function () {
    $this->actingAs($this->user, 'sanctum')
        ->patchJson($this->url, ['priority_id' => 99999])
        ->assertStatus(422)
        ->assertJsonValidationErrors('priority_id');
});

it('requires authentication', function () {
    auth()->guard('web')->logout();

    $this->patchJson($this->url, ['title' => 'Nope'])->assertUnauthorized();
});

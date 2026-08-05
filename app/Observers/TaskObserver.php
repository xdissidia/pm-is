<?php

namespace App\Observers;

use App\Enums\StormTicketStatus;
use App\Jobs\SyncStormTicket;
use App\Models\Task;
use App\Support\StormSync;

class TaskObserver
{
    /**
     * Handle the Task "created" event.
     */
    public function created(Task $task): void
    {
        $task->activities()->create([
            'project_id' => $task->project_id,
            'user_id' => auth()->id(),
            'title' => 'New task',
            'subtitle' => "\"{$task->name}\" was created by ".auth()->user()->name,
        ]);

        if ($task->assigned_to_user_id !== null) {
            $task->assigned_at = now();
            $task->saveQuietly();
        }
    }

    /**
     * Handle the Task "updated" event.
     */
    public function updated(Task $task): void
    {
        if ($task->isDirty('name')) {
            $task->activities()->create([
                'project_id' => $task->project_id,
                'user_id' => auth()->id(),
                'title' => 'Task name was changed',
                'subtitle' => "from \"{$task->getOriginal('name')}\" to \"{$task->name}\" by ".auth()->user()->name,
            ]);
        }
        if ($task->isDirty('description')) {
            $task->activities()->create([
                'project_id' => $task->project_id,
                'user_id' => auth()->id(),
                'title' => 'Task description was changed',
                'subtitle' => "on \"{$task->name}\" by ".auth()->user()->name,
            ]);
        }
        if ($task->isDirty('assigned_to_user_id')) {
            $task->activities()->create([
                'project_id' => $task->project_id,
                'user_id' => auth()->id(),
                'title' => $task->assigned_to_user_id ? 'Assigned user to task' : 'Assigned user was removed',
                'subtitle' => $task->assigned_to_user_id
                    ? "\"{$task->name}\" was assigned to {$task->assignedToUser->name} by ".auth()->user()->name
                    : "on task \"{$task->name}\" by ".auth()->user()->name,
            ]);

            $task->assigned_at = now();
            $task->saveQuietly();
        }
        if ($task->isDirty('due_on')) {
            $task->activities()->create([
                'project_id' => $task->project_id,
                'user_id' => auth()->id(),
                'title' => $task->due_on ? 'Due date was set on task' : 'Due date was removed',
                'subtitle' => $task->due_on
                    ? "to {$task->due_on->format('F j, Y')} on \"{$task->name}\" by ".auth()->user()->name
                    : "on \"{$task->name}\" task by ".auth()->user()->name,
            ]);
        }
        if ($task->isDirty('estimation')) {
            $task->activities()->create([
                'project_id' => $task->project_id,
                'user_id' => auth()->id(),
                'title' => 'Estimation was set',
                'subtitle' => "to {$task->estimation}h on \"{$task->name}\" by ".auth()->user()->name,
            ]);
        }
        if ($task->isDirty('completed_at')) {
            $task->activities()->create([
                'project_id' => $task->project_id,
                'user_id' => auth()->id(),
                'title' => $task->completed_at ? 'Task was completed' : 'Task was set to uncompleted',
                'subtitle' => "\"{$task->name}\" was set as ".($task->completed_at ? 'completed' : 'uncompleted').' by '.auth()->user()->name,
            ]);
        }

        $this->syncStormTicket($task);
    }

    /**
     * Push what STORM has a field for onto the ticket this task mirrors. Every
     * write path lands here — the board, the drawer, and the move/complete
     * actions — so it catches changes no single event covers.
     */
    protected function syncStormTicket(Task $task): void
    {
        if (blank($task->storm_ticket_id) || StormSync::suspended()) {
            return;
        }

        $changes = [];

        if ($task->wasChanged('name')) {
            $changes['title'] = $task->name;
        }

        if ($task->wasChanged('description')) {
            $changes['body'] = $task->description;
        }

        // Completing a task and moving it between columns are the two things
        // that mean the same as a ticket changing state.
        if ($task->wasChanged('completed_at') || $task->wasChanged('group_id')) {
            $status = $task->completed_at !== null
                ? StormTicketStatus::CLOSED
                : StormTicketStatus::forTaskGroup($task->taskGroup()->value('name'));

            $changes['status'] = $status->value;

            // Completing is what *resolves* the ticket (`resolved` is STORM's
            // slug for "Closed (resolved)"), and un-completing puts it back to
            // ongoing — a bare move into Done only closes it. A move into a
            // working column marks both fields ongoing.
            if ($task->wasChanged('completed_at')) {
                $changes['work_status'] = $task->completed_at !== null ? 'resolved' : 'ongoing';
            } elseif ($status === StormTicketStatus::ON_GOING) {
                $changes['work_status'] = 'ongoing';
            }
        }

        if (! empty($changes)) {
            SyncStormTicket::dispatch($task, $changes);
        }
    }

    /**
     * Handle the Project "archived" event.
     */
    public function archived(Task $task): void
    {
        $task->activities()->create([
            'project_id' => $task->project_id,
            'user_id' => auth()->id(),
            'title' => 'Task was archived',
            'subtitle' => "\"{$task->name}\" was archived by ".auth()->user()->name,
        ]);
    }

    /**
     * Handle the Project "unArchived" event.
     */
    public function unArchived(Task $task): void
    {
        $task->activities()->create([
            'project_id' => $task->project_id,
            'user_id' => auth()->id(),
            'title' => 'Task was unarchived',
            'subtitle' => "\"{$task->name}\" was unarchived by ".auth()->user()->name,
        ]);
    }
}

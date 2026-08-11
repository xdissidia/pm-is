<?php

namespace App\Actions\Task;

use App\Enums\PricingType;
use App\Events\Task\TaskUpdated;
use App\Jobs\SyncStormTicket;
use App\Models\Label;
use App\Models\Task;
use App\Support\StormSync;

class UpdateTask
{
    public function update(Task $task, array $data): void
    {
        $updateField = key($data);

        if ($updateField === 'pricing_type' && $data['pricing_type'] === PricingType::HOURLY->value) {
            $task->update([
                'pricing_type' => PricingType::HOURLY,
                'fixed_price' => null,
            ]);
        }

        if ($updateField === 'fixed_price' && isset($data['fixed_price'])) {
            $data['fixed_price'] = (int) $data['fixed_price'];
        }

        if (! in_array($updateField, ['subscribed_users', 'labels'])) {
            $task->update($data);

            if ($updateField === 'group_id') {
                $task->update(['order_column' => 0]);
            }
        }

        if ($updateField === 'subscribed_users') {
            $task->subscribedUsers()->sync($data['subscribed_users']);
        }

        if ($updateField === 'assignees') {
            $this->syncStormAssignees($task, $task->assignees()->sync($data['assignees']));
        }

        if ($updateField === 'labels') {
            $this->syncBlockedWorkStatus($task, $task->labels()->sync($data['labels']));
        }

        TaskUpdated::dispatch($task, $updateField);
    }

    /**
     * Tell STORM when a ticketed task's assignees change, restating the whole
     * list by employee number — the identifier both systems share, the same
     * shape FileStormTicket files with. Assignees without a number cannot be
     * stated and are left out. Pivot syncs fire no model events, so this
     * cannot live in TaskObserver with the rest of the ticket sync.
     *
     * @param  array{attached: array<int, int>, detached: array<int, int>, updated: array<int, int>}  $changes
     */
    protected function syncStormAssignees(Task $task, array $changes): void
    {
        if (blank($task->storm_ticket_id) || StormSync::suspended()) {
            return;
        }

        if (empty($changes['attached']) && empty($changes['detached'])) {
            return;
        }

        $numbers = $task->assignees()
            ->pluck('employee_number')
            ->filter()
            ->values()
            ->all();

        SyncStormTicket::dispatch($task, ['assignees' => $numbers]);
    }

    /**
     * Tell STORM when the "Blocked" label comes or goes on a ticketed task —
     * blocked is what its `unfinished` work status means. Only a done task
     * speaks: unfinished is a way of being closed, so blocking a live task
     * says nothing until it is completed. Untagging a done task recants to
     * resolved. Pivot syncs fire no model events, so this cannot live in
     * TaskObserver with the rest of the ticket sync.
     *
     * @param  array{attached: array<int, int>, detached: array<int, int>}  $changes
     */
    protected function syncBlockedWorkStatus(Task $task, array $changes): void
    {
        if (blank($task->storm_ticket_id) || StormSync::suspended() || $task->completed_at === null) {
            return;
        }

        $blockedId = Label::where('name', 'Blocked')->value('id');

        if ($blockedId === null) {
            return;
        }

        if (in_array($blockedId, $changes['attached'])) {
            SyncStormTicket::dispatch($task, ['work_status' => 'unfinished']);
        } elseif (in_array($blockedId, $changes['detached'])) {
            SyncStormTicket::dispatch($task, ['work_status' => 'resolved']);
        }
    }
}

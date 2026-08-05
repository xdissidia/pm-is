<?php

namespace App\Actions\Task;

use App\Enums\PricingType;
use App\Events\Task\AttachmentsUploaded;
use App\Events\Task\TaskCreated;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use Throwable;

class CreateTask
{
    /**
     * A null project stores the task unfiled — no project, no group, and no
     * number, since numbering runs per project. MoveTaskToGroup fills all three
     * in when the task is later moved into a group.
     */
    public function create(?Project $project, array $data): Task
    {
        return DB::transaction(function () use ($project, $data) {
            if (isset($data['pricing_type']) && $data['pricing_type'] === PricingType::HOURLY->value) {
                $data['fixed_price'] = null;
            } elseif (isset($data['fixed_price']) && isset($data['pricing_type']) && $data['pricing_type'] === PricingType::FIXED->value) {
                $data['fixed_price'] = (int) ($data['fixed_price'] * 100);
            }

            $task = Task::create([
                'project_id' => $project?->id,
                'group_id' => $data['group_id'] ?? null,
                'created_by_user_id' => auth()->id(),
                // Set when STORM files the task — it is what stops the ticket
                // from being filed straight back to STORM (see FileStormTicket).
                'storm_ticket_id' => $data['storm_ticket_id'] ?? null,
                // 'assigned_users' => $data['assigned_users'],
                'name' => $data['name'],
                'number' => $project ? $project->tasks()->withArchived()->count() + 1 : null,
                'description' => $data['description'],
                'due_on' => $data['due_on'],
                'estimation' => $data['estimation'],
                'priority_id' => $data['priority_id'] ?? null,
                'pricing_type' => $data['pricing_type'] ?? null,
                'fixed_price' => $data['fixed_price'] ?? null,
                'hidden_from_clients' => $data['hidden_from_clients'],
                'billable' => $data['billable'],
                'completed_at' => null,
            ]);

            $task->subscribedUsers()->attach($data['subscribed_users'] ?? []);

            $task->assignees()->sync($data['assigned_users'] ?? []);

            $task->labels()->attach($data['labels'] ?? []);

            if (! empty($data['attachments'])) {
                $this->uploadAttachments($task, $data['attachments'], false, $data['attachment_storm_ids'] ?? []);
            }

            TaskCreated::dispatch($task);

            return $task;
        });
    }

    /**
     * @param  array<int|string, UploadedFile>  $items
     * @param  array<int|string, int>  $stormAttachmentIds  STORM's id for an item, under the same key
     */
    public function uploadAttachments(Task $task, array $items, $dispatchEvent = true, array $stormAttachmentIds = []): Collection
    {
        $rows = collect($items)
            ->map(function (UploadedFile $item, $key) use ($task, $stormAttachmentIds) {
                $filename = strtolower(Str::ulid()).'.'.$item->getClientOriginalExtension();
                $filepath = "tasks/{$task->id}/{$filename}";

                $item->storeAs('public', $filepath);

                $thumbFilepath = $this->generateThumb($item, $task, $filename);

                return [
                    'storm_attachment_id' => $stormAttachmentIds[$key] ?? null,
                    'user_id' => auth()->id(),
                    'name' => $item->getClientOriginalName(),
                    'path' => "/storage/$filepath",
                    'thumb' => $thumbFilepath ? "/storage/$thumbFilepath" : null,
                    'type' => $item->getClientMimeType(),
                    'size' => $item->getSize(),
                ];
            });

        $attachments = $task->attachments()->createMany($rows);

        $task->activities()->create([
            'project_id' => $task->project_id,
            'user_id' => auth()->id(),
            'title' => ($attachments->count() > 1 ? 'Attachments where' : 'Attachment was').' uploaded',
            'subtitle' => "to \"{$task->name}\" by ".auth()->user()->name,
        ]);

        if ($dispatchEvent) {
            AttachmentsUploaded::dispatch($task, $attachments);
        }

        return $attachments;
    }

    protected function generateThumb(UploadedFile $file, Task $task, string $filename)
    {
        if (in_array($file->getClientOriginalExtension(), ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
            try {
                $thumbFilepath = "tasks/{$task->id}/thumbs/{$filename}";

                $image = Image::make($file->get())
                    ->fit(100, 100)
                    ->encode(null, 75);

                Storage::put("public/$thumbFilepath", $image);

                return $thumbFilepath;
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }
}

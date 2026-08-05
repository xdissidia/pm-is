<?php

namespace App\Http\Resources\Task;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            // The STORM ticket this task mirrors — echoed back so STORM can
            // confirm the link it sent, or pick up the one PMIS filed.
            'storm_ticket_id' => $this->storm_ticket_id,
            'title' => $this->name,
            'body' => $this->description,
            'project' => [
                'id' => $this->project_id,
                'name' => $this->whenLoaded('project', fn () => $this->project->name),
            ],
            'group' => [
                'id' => $this->group_id,
                'name' => $this->whenLoaded('taskGroup', fn () => $this->taskGroup->name),
            ],
            // Not whenLoaded(): Task casts an attribute called `priority`, which
            // shadows the relation, so `$task->priority` — and therefore
            // whenLoaded's own null check — always reads null.
            'priority' => $this->resource->relationLoaded('priority')
                ? $this->resource->getRelation('priority')?->only(['id', 'label', 'color'])
                : null,
            'due_on' => $this->due_on?->toDateString(),
            'estimation' => $this->estimation,
            'billable' => $this->billable,
            'hidden_from_clients' => $this->hidden_from_clients,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('createdByUser', fn () => $this->createdByUser?->only(['id', 'name'])),
            'assignees' => $this->whenLoaded('assignees', fn () => $this->assignees->map->only(['id', 'name'])),
            'subscribers' => $this->whenLoaded('subscribedUsers', fn () => $this->subscribedUsers->map->only(['id'])),
            'labels' => $this->whenLoaded('labels', fn () => $this->labels->map->only(['id', 'name', 'color'])),
            'attachments' => $this->whenLoaded(
                'attachments',
                fn () => $this->attachments->map->only(['id', 'name', 'path', 'thumb', 'type', 'size']),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

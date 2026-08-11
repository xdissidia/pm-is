<?php

namespace App\Services\Storm;

use App\Enums\StormTicketStatus;
use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\UploadedFile;

/**
 * Files tickets into STORM (POST /api/v1/pmis/tickets) and updates them
 * afterwards. This is the outbound half of the integration — the inbound half,
 * STORM creating and updating PMIS tasks, is Api\V1\TaskController.
 */
class StormTicketService extends StormClient
{
    /**
     * The fields STORM's ticket request accepts. Anything else in the payload
     * is dropped rather than sent, so callers can hand over a wider array.
     */
    public const FIELDS = [
        'title',
        'body',
        'category_id',
        'task_group_id',
        'pmis_user_id',
        'author_employee_number',
        'pmis_task_id',
        'section_id',
        'priority_id',
        'level',
        'status',
        'work_status',
        'assignees',
        // Update only: STORM attachment ids to drop off the ticket.
        'remove_attachments',
    ];

    /**
     * What STORM's upload rules allow: 20 files, 25600 KB each.
     */
    public const MAX_UPLOADS = 20;

    public const MAX_UPLOAD_BYTES = 25600 * 1024;

    /**
     * File a new ticket. Returns the ticket STORM created.
     *
     * @param  array<string, mixed>  $ticket  Keys from self::FIELDS; `status` also takes a StormTicketStatus.
     * @param  array<int, UploadedFile|string|array{path: string, name?: string}>  $uploads  Uploaded files or readable paths.
     * @return array<string, mixed>
     *
     * @throws StormApiException
     */
    public function create(array $ticket, array $uploads = []): array
    {
        $payload = $this->payload($ticket);

        // The title is the one field STORM insists on. The body is not: a task
        // can be filed without a description, and STORM takes a null body.
        if (blank($payload['title'] ?? null)) {
            throw new StormApiException('A STORM ticket needs a title.');
        }

        return $this->ticket($this->send('post', $this->endpoint(), $payload, $uploads));
    }

    /**
     * Partially update a ticket — only the fields present are sent.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<int, UploadedFile|string|array{path: string, name?: string}>  $uploads
     * @return array<string, mixed>
     *
     * @throws StormApiException
     */
    public function update(int|string $ticketId, array $changes, array $uploads = []): array
    {
        $payload = $this->payload($changes);

        if (empty($payload) && empty($uploads)) {
            throw new StormApiException('Nothing to update: send at least one of '.implode(', ', self::FIELDS).' or an upload.');
        }

        // PHP cannot parse a multipart body on a real PATCH, so anything with
        // files goes out as POST + _method=PATCH, which STORM's route allows.
        if (! empty($uploads)) {
            return $this->ticket($this->send('post', $this->endpoint($ticketId), $payload + ['_method' => 'PATCH'], $uploads));
        }

        return $this->ticket($this->send('patch', $this->endpoint($ticketId), $payload));
    }

    /**
     * File a ticket for an existing PMIS task. Only what PMIS actually knows is
     * mapped — STORM-side ids (category, section, priority, level) have no PMIS
     * equivalent, so pass those in $extra when the caller has them.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<int, UploadedFile|string|array{path: string, name?: string}>  $uploads
     * @return array<string, mixed>
     *
     * @throws StormApiException
     */
    public function createFromTask(Task $task, array $extra = [], array $uploads = []): array
    {
        return $this->create(array_merge([
            'title' => $task->name,
            'body' => $task->description,
            'pmis_task_id' => $task->id,
            'task_group_id' => $task->group_id,
            'pmis_user_id' => $task->created_by_user_id,
            // Queried rather than read off the relation: it is usually loaded
            // with a narrow column list that leaves out the employee number.
            'author_employee_number' => $task->createdByUser()->value('employee_number'),
        ], $extra), $uploads);
    }

    /**
     * Record STORM's ids for files it just took, matching the `attachments` it
     * answers with back to the PMIS rows by name. Only rows that do not have an
     * id yet are stamped, so re-uploading later cannot re-point an older file.
     *
     * @param  iterable<Attachment>  $sent
     * @param  array<string, mixed>  $ticket
     */
    public static function linkAttachments(iterable $sent, array $ticket): void
    {
        $unlinked = collect($sent)->filter(fn (Attachment $attachment) => blank($attachment->storm_attachment_id));

        if ($unlinked->isEmpty()) {
            return;
        }

        $taken = $unlinked->pluck('storm_attachment_id')->filter()->all();

        foreach ($ticket['attachments'] ?? [] as $theirs) {
            if (! is_array($theirs) || blank($theirs['id'] ?? null)) {
                continue;
            }

            $match = $unlinked->first(fn (Attachment $attachment) => blank($attachment->storm_attachment_id)
                && $attachment->name === ($theirs['name'] ?? null)
                && ! in_array($theirs['id'], $taken, true));

            if (! $match) {
                continue;
            }

            $taken[] = $theirs['id'];
            $match->storm_attachment_id = (int) $theirs['id'];
            $match->save();
        }
    }

    /**
     * Turn task attachments into upload entries, dropping what STORM would
     * reject anyway: files that are gone, oversized, or past the 20-file cap.
     *
     * @param  iterable<Attachment>  $attachments
     * @return array<int, array{path: string, name: string}>
     */
    public static function uploadsFrom(iterable $attachments): array
    {
        return collect($attachments)
            ->map(fn (Attachment $attachment) => [
                'path' => $attachment->absolutePath(),
                'name' => $attachment->name,
            ])
            ->filter(fn (array $upload) => $upload['path'] !== null
                && filesize($upload['path']) <= self::MAX_UPLOAD_BYTES)
            ->take(self::MAX_UPLOADS)
            ->values()
            ->all();
    }

    protected function endpoint(int|string|null $ticketId = null): string
    {
        return $this->url('tickets'.($ticketId !== null ? "/{$ticketId}" : ''));
    }

    /**
     * STORM wraps the ticket it answers with in `data`.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function ticket(array $body): array
    {
        return $body['data'] ?? $body;
    }

    /**
     * Keep only the fields STORM understands, and unwrap the status enum.
     *
     * @param  array<string, mixed>  $ticket
     * @return array<string, mixed>
     */
    protected function payload(array $ticket): array
    {
        $payload = array_intersect_key($ticket, array_flip(self::FIELDS));

        if (($payload['status'] ?? null) instanceof StormTicketStatus) {
            $payload['status'] = $payload['status']->value;
        }

        return $payload;
    }
}

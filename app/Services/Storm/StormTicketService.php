<?php

namespace App\Services\Storm;

use App\Enums\StormTicketStatus;
use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Files tickets into STORM (POST /api/v1/pmis/tickets) and updates them
 * afterwards. This is the outbound half of the integration — the inbound half,
 * STORM creating and updating PMIS tasks, is Api\V1\TaskController.
 */
class StormTicketService
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
        'author_email',
        'pmis_task_id',
        'section_id',
        'priority_id',
        'level',
        'status',
        'work_status',
        'assignees',
    ];

    /**
     * What STORM's upload rules allow: 20 files, 25600 KB each.
     */
    public const MAX_UPLOADS = 20;

    public const MAX_UPLOAD_BYTES = 25600 * 1024;

    protected string $baseUrl;

    protected ?string $token;

    protected int $timeout;

    public function __construct(?string $baseUrl = null, ?string $token = null, ?int $timeout = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (string) config('services.storm.url'), '/');
        $this->token = $token ?? config('services.storm.token');
        $this->timeout = $timeout ?? (int) config('services.storm.timeout', 20);
    }

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

        foreach (['title', 'body'] as $required) {
            if (blank($payload[$required] ?? null)) {
                throw new StormApiException("A STORM ticket needs a {$required}.");
            }
        }

        return $this->send('post', $this->endpoint(), $payload, $uploads);
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
            return $this->send('post', $this->endpoint($ticketId), $payload + ['_method' => 'PATCH'], $uploads);
        }

        return $this->send('patch', $this->endpoint($ticketId), $payload);
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
            // with a narrow column list that leaves out the email.
            'author_email' => $task->createdByUser()->value('email'),
        ], $extra), $uploads);
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, UploadedFile|string|array{path: string, name?: string}>  $uploads
     * @return array<string, mixed>
     *
     * @throws StormApiException
     */
    protected function send(string $method, string $url, array $payload, array $uploads = []): array
    {
        $request = $this->request();

        // Files force multipart, where every value has to be a scalar part.
        if (! empty($uploads)) {
            foreach ($uploads as $upload) {
                $request = $this->attach($request, $upload);
            }

            $payload = $this->flatten($payload);
        }

        try {
            $response = $request->{$method}($url, $payload);
        } catch (ConnectionException $e) {
            throw StormApiException::unreachable($url, $e);
        }

        if ($response->failed()) {
            throw StormApiException::fromResponse($response);
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    /**
     * @throws StormApiException
     */
    protected function request(): PendingRequest
    {
        if (blank($this->token)) {
            throw new StormApiException('No STORM API token configured — set STORM_API_TOKEN.');
        }

        return Http::withToken($this->token)
            ->acceptJson()
            ->timeout($this->timeout)
            ->when(
                ! config('services.storm.verify', true),
                fn (PendingRequest $request) => $request->withoutVerifying(),
            );
    }

    protected function endpoint(int|string|null $ticketId = null): string
    {
        return $this->baseUrl.'/api/v1/pmis/tickets'.($ticketId !== null ? "/{$ticketId}" : '');
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

    /**
     * @throws StormApiException
     */
    protected function attach(PendingRequest $request, mixed $upload): PendingRequest
    {
        // Attached as streams, not strings: it keeps a 25MB upload out of
        // memory, and an empty file would otherwise be dropped by the
        // array_filter() inside PendingRequest::attach().
        if ($upload instanceof UploadedFile) {
            return $request->attach('uploads[]', fopen($upload->getPathname(), 'r'), $upload->getClientOriginalName());
        }

        // ['path' => ..., 'name' => ...] — a stored file that keeps the name it
        // was uploaded under rather than the ULID it is stored as.
        if (is_array($upload) && is_file($upload['path'] ?? '')) {
            return $request->attach(
                'uploads[]',
                fopen($upload['path'], 'r'),
                $upload['name'] ?? basename($upload['path']),
            );
        }

        if (is_string($upload) && is_file($upload)) {
            return $request->attach('uploads[]', fopen($upload, 'r'), basename($upload));
        }

        throw new StormApiException('Uploads have to be UploadedFile instances or readable file paths.');
    }

    /**
     * Multipart parts are name/value pairs of strings, so nested arrays become
     * `assignees[0]` and booleans become 1/0. Nulls are dropped — STORM treats
     * every one of these fields as nullable anyway.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function flatten(array $payload, string $prefix = ''): array
    {
        $flat = [];

        foreach ($payload as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";

            if (is_array($value)) {
                $flat = array_merge($flat, $this->flatten($value, $name));
            } elseif (is_bool($value)) {
                $flat[$name] = $value ? '1' : '0';
            } elseif ($value !== null) {
                $flat[$name] = (string) $value;
            }
        }

        return $flat;
    }
}

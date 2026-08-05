<?php

namespace App\Services\Storm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Shared plumbing for the STORM endpoints PMIS calls: where they live, the
 * bearer token they authenticate with, and how failures are reported.
 */
abstract class StormClient
{
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
     * Everything PMIS calls sits under the same /api/v1/pmis prefix.
     */
    protected function url(string $path): string
    {
        return $this->baseUrl.'/api/v1/pmis/'.ltrim($path, '/');
    }

    /**
     * Send a request and hand back the decoded body.
     *
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

        return $response->json() ?? [];
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

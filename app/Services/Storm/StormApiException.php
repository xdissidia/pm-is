<?php

namespace App\Services\Storm;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use RuntimeException;
use Throwable;

class StormApiException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors  Laravel validation errors as STORM returned them.
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, (int) $status, $previous);
    }

    /**
     * STORM answered, but with a 4xx/5xx. Validation errors are folded into the
     * message so a log line is enough to see which field it choked on.
     */
    public static function fromResponse(Response $response): self
    {
        $body = $response->json() ?? [];
        $errors = (array) ($body['errors'] ?? []);

        $message = $body['message'] ?? "request failed with HTTP {$response->status()}";

        if (! empty($errors)) {
            $message .= ' ('.implode(' ', Arr::flatten($errors)).')';
        }

        return new self("STORM API: {$message}", $response->status(), $errors);
    }

    /**
     * STORM never answered — down, wrong host, or timed out.
     */
    public static function unreachable(string $url, Throwable $previous): self
    {
        return new self("STORM API at {$url} could not be reached: {$previous->getMessage()}", null, [], $previous);
    }
}

<?php

use App\Enums\StormTicketStatus;
use App\Services\Storm\StormApiException;
use App\Services\Storm\StormTicketService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.storm.url' => 'http://localhost:9000',
        'services.storm.token' => 'test-token',
    ]);

    $this->storm = new StormTicketService;
});

it('posts a ticket to storm with the sanctum token', function () {
    Http::fake([
        'localhost:9000/api/v1/pmis/tickets' => Http::response(['data' => ['id' => 7, 'title' => 'Radar is down']], 201),
    ]);

    $ticket = $this->storm->create([
        'title' => 'Radar is down',
        'body' => '<p>No returns since 0300.</p>',
        'pmis_task_id' => 42,
        'status' => StormTicketStatus::OPEN,
        'assignees' => [3, 9],
        'ignored' => 'not a storm field',
    ]);

    expect($ticket)->toBe(['id' => 7, 'title' => 'Radar is down']);

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://localhost:9000/api/v1/pmis/tickets'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Accept', 'application/json')
            && $request['status'] === 'Open'
            && $request['assignees'] === [3, 9]
            && ! isset($request['ignored']);
    });
});

it('sends uploads as multipart', function () {
    Http::fake(['localhost:9000/*' => Http::response(['data' => ['id' => 8]], 201)]);

    $this->storm->create(
        ['title' => 'Ticket with a file', 'body' => 'See attached.', 'assignees' => [3]],
        [UploadedFile::fake()->create('log.txt', 2)],
    );

    Http::assertSent(function (Request $request) {
        $names = collect($request->data())->pluck('name');

        return $request->isMultipart()
            && $names->contains('uploads[]')
            // Nested values are flattened into bracket notation for multipart.
            && $names->contains('assignees[0]');
    });
});

it('patches a ticket, and falls back to post + _method for uploads', function () {
    Http::fake(['localhost:9000/*' => Http::response(['data' => ['id' => 7]])]);

    $this->storm->update(7, ['status' => 'Closed']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
        && $request->url() === 'http://localhost:9000/api/v1/pmis/tickets/7'
        && $request['status'] === 'Closed');

    $this->storm->update(7, ['work_status' => 'Resolved'], [UploadedFile::fake()->create('fix.pdf', 2)]);

    Http::assertSent(function (Request $request) {
        if ($request->method() !== 'POST') {
            return false;
        }

        $parts = collect($request->data())->pluck('contents', 'name');

        return $parts['_method'] === 'PATCH' && $parts['work_status'] === 'Resolved';
    });
});

it('refuses to send an incomplete or empty payload', function () {
    Http::fake();

    expect(fn () => $this->storm->create(['title' => 'No body']))
        ->toThrow(StormApiException::class, 'needs a body');

    expect(fn () => $this->storm->update(7, ['nothing' => 'storm knows about']))
        ->toThrow(StormApiException::class, 'Nothing to update');

    Http::assertNothingSent();
});

it('fails loudly without a token', function () {
    config(['services.storm.token' => null]);

    expect(fn () => (new StormTicketService)->create(['title' => 'A', 'body' => 'B']))
        ->toThrow(StormApiException::class, 'STORM_API_TOKEN');
});

it('surfaces storm validation errors', function () {
    Http::fake([
        'localhost:9000/*' => Http::response([
            'message' => 'The given data was invalid.',
            'errors' => ['category_id' => ['The selected category id is invalid.']],
        ], 422),
    ]);

    try {
        $this->storm->create(['title' => 'Bad category', 'body' => 'x', 'category_id' => 999]);

        $this->fail('Expected a StormApiException.');
    } catch (StormApiException $e) {
        expect($e->status)->toBe(422)
            ->and($e->errors)->toHaveKey('category_id')
            ->and($e->getMessage())->toContain('The selected category id is invalid.');
    }
});

it('reports storm being unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => $this->storm->create(['title' => 'A', 'body' => 'B']))
        ->toThrow(StormApiException::class, 'could not be reached');
});

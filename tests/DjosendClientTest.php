<?php

declare(strict_types=1);

namespace Djosend\Tests;

use Djosend\DjosendClient;
use Djosend\Exception\ApiException;
use Djosend\Exception\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class DjosendClientTest extends TestCase
{
    private const ID = '2816db3d-29a5-4f91-81c8-dc88f69c3bab';

    /** @var list<array<string, mixed>> */
    private array $history;

    /** @param list<Response> $responses */
    private function client(array $responses, int $maxRetries = 0): DjosendClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new DjosendClient('test-key', 'https://sandbox.example', new Client(['handler' => $stack]), $maxRetries);
    }

    private function response(array $data, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers, json_encode(['success' => true, 'data' => $data], JSON_THROW_ON_ERROR));
    }

    public function testSendMessageUsesCurrentEndpointAndHeaderOnlyIdempotency(): void
    {
        $message = ['id' => self::ID, 'status' => 'queued'];
        $client = $this->client([$this->response($message, 202)]);

        self::assertSame($message, $client->sendMessage([
            'channel' => 'sms', 'to' => '+2250102030405', 'body' => 'Bonjour',
        ], 'message-1'));

        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://sandbox.example/api/v1/messages', (string) $request->getUri());
        self::assertSame('test-key', $request->getHeaderLine('x-api-key'));
        self::assertSame('message-1', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame(['channel' => 'sms', 'to' => '+2250102030405', 'body' => 'Bonjour'],
            json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRetryKeepsSameIdempotencyKeyOnTransientFailure(): void
    {
        $client = $this->client([
            new Response(503, [], '{"message":"Unavailable"}'),
            $this->response(['id' => self::ID], 202),
        ], 1);

        self::assertSame(['id' => self::ID], $client->sendMessage([
            'channel' => 'sms', 'to' => '+2250102030405', 'body' => 'Bonjour',
        ], 'stable-key'));
        self::assertCount(2, $this->history);
        self::assertSame('stable-key', $this->history[1]['request']->getHeaderLine('Idempotency-Key'));
    }

    public function testReadErrorPreservesStatusAndRequestIdWithoutRetry(): void
    {
        $client = $this->client([
            new Response(404, ['X-Request-Id' => 'req-1'], '{"message":"Not found"}'),
        ], 2);

        try {
            $client->getMessage(self::ID);
            self::fail('Expected ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(404, $exception->statusCode);
            self::assertSame('req-1', $exception->requestId);
            self::assertSame('Not found', $exception->getMessage());
        }
        self::assertCount(1, $this->history);
    }

    public function testListScheduleCancelAndCapabilitiesUseExpectedRoutes(): void
    {
        $client = $this->client([
            $this->response(['items' => [], 'pagination' => ['page' => 2]]),
            $this->response(['id' => self::ID], 202),
            $this->response(['id' => self::ID]),
            $this->response([['channel' => 'sms', 'available' => true]]),
        ]);

        $client->listMessages(['page' => 2, 'limit' => 10, 'channel' => 'sms']);
        $client->scheduleMessage([
            'to' => '+2250102030405', 'body' => 'Bonjour',
            'scheduledAt' => '2026-11-01T10:00:00Z', 'timezone' => 'Africa/Abidjan',
        ], 'schedule-1');
        $client->cancelScheduledMessage(self::ID);
        $client->getChannelCapabilities();

        self::assertSame('/api/v1/messages?page=2&limit=10&channel=sms', $this->history[0]['request']->getRequestTarget());
        self::assertSame('/api/v1/messages/scheduled', $this->history[1]['request']->getRequestTarget());
        self::assertSame('DELETE', $this->history[2]['request']->getMethod());
        self::assertSame('/api/v1/messages/scheduled/' . self::ID, $this->history[2]['request']->getRequestTarget());
        self::assertSame('/api/v1/messages/capabilities', $this->history[3]['request']->getRequestTarget());
    }

    public function testRejectsUnsafeInputBeforeSending(): void
    {
        $client = $this->client([]);
        $this->expectException(ValidationException::class);
        $client->sendMessage(['channel' => 'sms', 'to' => '+2250102030405', 'body' => 'Bonjour'], '');
    }
}

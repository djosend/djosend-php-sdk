<?php

declare(strict_types=1);

namespace Djosend;

use Djosend\Exception\ApiException;
use Djosend\Exception\DjosendException;
use Djosend\Exception\TransportException;
use Djosend\Exception\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

final class DjosendClient
{
    private const DEFAULT_BASE_URL = 'https://api.djosend.com';
    private const CHANNELS = ['sms', 'whatsapp', 'email', 'voice', 'auto'];
    private const STATUSES = ['queued', 'sent', 'delivered', 'failed', 'unknown'];

    private readonly string $baseUrl;
    private readonly ClientInterface $httpClient;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?ClientInterface $httpClient = null,
        private readonly int $maxRetries = 2,
    ) {
        if (trim($apiKey) === '') {
            throw new ValidationException('An API key is required.');
        }
        if ($maxRetries < 0 || $maxRetries > 5) {
            throw new ValidationException('maxRetries must be between 0 and 5.');
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (
            $parts === false || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || !empty($parts['path'])
        ) {
            throw new ValidationException('baseUrl must be an HTTPS origin without credentials, path, query or fragment.');
        }

        $this->baseUrl = $baseUrl;
        $this->httpClient = $httpClient ?? new Client();
    }

    /**
     * Queue a message for asynchronous delivery. The key is required for safe retries.
     *
     * @param array{channel: string, to: string, body: string, senderNameId?: string, metadata?: array<string, mixed>} $input
     * @return array<string, mixed>
     */
    public function sendMessage(array $input, string $idempotencyKey): array
    {
        $this->validateMessage($input);
        $this->validateIdempotencyKey($idempotencyKey);

        return $this->request('POST', '/api/v1/messages', $input, [], $idempotencyKey, true);
    }

    /** @return array<string, mixed> */
    public function getMessage(string $id): array
    {
        return $this->request('GET', '/api/v1/messages/' . $this->encodeId($id));
    }

    /**
     * @param array{page?: int, limit?: int, status?: string, channel?: string, search?: string, from?: string, to?: string} $filters
     * @return array<string, mixed>
     */
    public function listMessages(array $filters = []): array
    {
        $allowed = ['page', 'limit', 'status', 'channel', 'search', 'from', 'to'];
        foreach ($filters as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_scalar($value)) {
                throw new ValidationException('Unsupported message filter: ' . (string) $key);
            }
        }
        if (isset($filters['page']) && (!is_int($filters['page']) || $filters['page'] < 1)) {
            throw new ValidationException('page must be a positive integer.');
        }
        if (isset($filters['limit']) && (!is_int($filters['limit']) || $filters['limit'] < 1 || $filters['limit'] > 100)) {
            throw new ValidationException('limit must be an integer between 1 and 100.');
        }
        if (isset($filters['status']) && !in_array($filters['status'], self::STATUSES, true)) {
            throw new ValidationException('Unsupported message status.');
        }
        if (isset($filters['channel']) && !in_array($filters['channel'], self::CHANNELS, true)) {
            throw new ValidationException('Unsupported message channel.');
        }

        return $this->request('GET', '/api/v1/messages', null, $filters);
    }

    /**
     * @param array{to: string, body: string, scheduledAt: string, timezone: string, senderNameId?: string, metadata?: array<string, mixed>} $input
     * @return array<string, mixed>
     */
    public function scheduleMessage(array $input, string $idempotencyKey): array
    {
        $this->validateInputKeys($input, ['to', 'body', 'scheduledAt', 'timezone', 'senderNameId', 'metadata']);
        $this->validateRecipientAndBody($input);
        foreach (['scheduledAt', 'timezone'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
                throw new ValidationException($field . ' is required.');
            }
        }
        if (isset($input['senderNameId'])) {
            $this->encodeId($input['senderNameId']);
        }
        $this->validateIdempotencyKey($idempotencyKey);

        return $this->request('POST', '/api/v1/messages/scheduled', $input, [], $idempotencyKey, true);
    }

    /** @return array<string, mixed> */
    public function cancelScheduledMessage(string $id): array
    {
        return $this->request('DELETE', '/api/v1/messages/scheduled/' . $this->encodeId($id));
    }

    /** @return list<array<string, mixed>> */
    public function getChannelCapabilities(): array
    {
        /** @var list<array<string, mixed>> $result */
        $result = $this->request('GET', '/api/v1/messages/capabilities');
        return $result;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?string $idempotencyKey = null,
        bool $retryable = false,
    ): array {
        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'x-api-key' => $this->apiKey,
            ],
            'http_errors' => false,
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'timeout' => 10,
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($idempotencyKey !== null) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }

        for ($attempt = 0; ; ++$attempt) {
            try {
                $response = $this->httpClient->request($method, $this->baseUrl . $path, $options);
            } catch (TransferException $exception) {
                if ($retryable && $attempt < $this->maxRetries) {
                    $this->backoff($attempt);
                    continue;
                }
                throw new TransportException('Djosend request failed during transport.', 0, $exception);
            }

            $status = $response->getStatusCode();
            if ($retryable && $attempt < $this->maxRetries && ($status === 429 || $status >= 500)) {
                $this->backoff($attempt);
                continue;
            }

            return $this->decodeResponse($response);
        }
    }

    /** @return array<string, mixed> */
    private function decodeResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if ($status < 200 || $status >= 300) {
                throw new ApiException(
                    'Djosend API returned HTTP ' . $status . '.',
                    $status,
                    $response->getHeaderLine('X-Request-Id') ?: null,
                );
            }
            throw new DjosendException('Djosend returned invalid JSON.', 0, $exception);
        }
        if (!is_array($payload)) {
            throw new DjosendException('Djosend returned an unexpected response.');
        }

        if ($status < 200 || $status >= 300 || ($payload['success'] ?? true) === false) {
            $message = $payload['message'] ?? null;
            if (is_array($message)) {
                $message = implode('; ', array_filter($message, 'is_string'));
            }
            if (!is_string($message) || $message === '') {
                $message = 'Djosend API returned HTTP ' . $status . '.';
            }
            $requestId = $response->getHeaderLine('X-Request-Id') ?: ($payload['requestId'] ?? null);
            throw new ApiException($message, $status, is_string($requestId) ? $requestId : null, $payload);
        }

        $data = ($payload['success'] ?? null) === true && array_key_exists('data', $payload)
            ? $payload['data'] : $payload;
        if (!is_array($data)) {
            throw new DjosendException('Djosend returned an unexpected data shape.');
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function validateMessage(array $input): void
    {
        $this->validateInputKeys($input, ['channel', 'to', 'body', 'senderNameId', 'metadata']);
        $this->validateRecipientAndBody($input);
        if (!isset($input['channel']) || !in_array($input['channel'], self::CHANNELS, true)) {
            throw new ValidationException('A valid channel is required.');
        }
        if (isset($input['senderNameId'])) {
            $this->encodeId($input['senderNameId']);
        }
    }

    /** @param array<string, mixed> $input */
    private function validateRecipientAndBody(array $input): void
    {
        foreach (['to', 'body'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
                throw new ValidationException($field . ' is required.');
            }
        }
        if (strlen($input['to']) < 6 || strlen($input['to']) > 25) {
            throw new ValidationException('Message recipient must contain 6 to 25 characters.');
        }
        if (isset($input['metadata']) && (!is_array($input['metadata']) || array_is_list($input['metadata']))) {
            throw new ValidationException('metadata must be an associative object; omit it when empty.');
        }
    }

    /** @param array<string, mixed> $input
     *  @param list<string> $allowed
     */
    private function validateInputKeys(array $input, array $allowed): void
    {
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new ValidationException('Unsupported message field: ' . (string) $key);
            }
        }
    }

    private function validateIdempotencyKey(string $key): void
    {
        if (trim($key) === '' || strlen($key) > 128 || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new ValidationException('Idempotency key must contain 1 to 128 characters without line breaks.');
        }
    }

    private function encodeId(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new ValidationException('A valid UUID is required.');
        }
        return rawurlencode($id);
    }

    private function backoff(int $attempt): void
    {
        usleep(100_000 * (2 ** $attempt));
    }
}

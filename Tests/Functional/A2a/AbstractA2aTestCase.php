<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\A2a;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * Drives the A2A endpoints through the real frontend middleware stack — the
 * API router, the traffic recorder and the handlers — the way an agent on
 * another host would call them.
 *
 * nr-llm is not installed in the test instance, so every task runs the
 * scripted skills: the mode a fresh installation is in.
 */
abstract class AbstractA2aTestCase extends AbstractAgentNexusTestCase
{
    protected const string API = self::BASE . 'api/agent-nexus/a2a/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootPage();
        $this->writeTestSite();
        // Rate-limit counters live in a cache that survives between the tests
        // of one class; every test starts with a fresh budget.
        $this->get(CacheManager::class)->getCache(RateLimiter::CACHE)->flush();
    }

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, string> $headers
     */
    protected function rpc(array $payload, ?string $version = '1.0', array $headers = []): ResponseInterface
    {
        if ($version !== null) {
            $headers['A2A-Version'] = $version;
        }
        return $this->post('jsonrpc', $payload, $headers);
    }

    /**
     * @param array<array-key, mixed>|null $payload
     * @param array<string, string> $headers
     */
    protected function post(string $path, ?array $payload, array $headers = []): ResponseInterface
    {
        $request = (new InternalRequest(self::API . $path))->withMethod('POST');
        if ($payload !== null) {
            $body = new Stream('php://temp', 'rw');
            $body->write((string)json_encode($payload));
            $body->rewind();
            $request = $request->withBody($body)->withHeader('Content-Type', 'application/json');
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $this->executeFrontendSubRequest($request);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function fetch(string $url, array $headers = []): ResponseInterface
    {
        $request = new InternalRequest(str_starts_with($url, 'https://') ? $url : self::API . $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $this->executeFrontendSubRequest($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded, 'The response is not JSON: ' . (string)$response->getBody());
        $json = [];
        foreach ($decoded as $key => $value) {
            $json[(string)$key] = $value;
        }
        return $json;
    }

    /**
     * The decoded `data:` payload of every Server-Sent Event.
     *
     * @return list<array<string, mixed>>
     */
    protected function sse(ResponseInterface $response): array
    {
        self::assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));
        $events = [];
        foreach (explode("\n", (string)$response->getBody()) as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $decoded = json_decode(trim(substr($line, 5)), true);
            if (is_array($decoded)) {
                $event = [];
                foreach ($decoded as $key => $value) {
                    $event[(string)$key] = $value;
                }
                $events[] = $event;
            }
        }
        self::assertNotSame([], $events, 'The stream carried no data frames.');
        return $events;
    }

    /**
     * A 1.0 user message.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected static function message(string $text, array $extra = []): array
    {
        return [
            'messageId' => 'm-' . bin2hex(random_bytes(4)),
            'role' => 'ROLE_USER',
            'parts' => [['text' => $text]],
        ] + $extra;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rows(string $table): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        return array_values($queryBuilder->select('*')->from($table)->orderBy('uid')->executeQuery()->fetchAllAssociative());
    }
}

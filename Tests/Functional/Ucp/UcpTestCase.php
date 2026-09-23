<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Ucp;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * Requests to the UCP endpoints through the real frontend middleware stack.
 */
abstract class UcpTestCase extends AbstractAgentNexusTestCase
{
    protected const string HOST = 'https://agent-nexus.test';
    protected const string API = self::HOST . '/api/agent-nexus/ucp';
    protected const string PLATFORM = 'profile="https://platform.example/.well-known/ucp"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootPage();
        $this->writeTestSite();
        // Rate limits and idempotency records live in this cache; every test starts clean.
        $this->get(CacheManager::class)->getCache('agentnexus')->flush();
    }

    /**
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $url, array $headers = [], string $body = ''): ResponseInterface
    {
        $request = (new InternalRequest($url))->withMethod($method);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== '') {
            $stream = new Stream('php://temp', 'rw');
            $stream->write($body);
            $stream->rewind();
            $request = $request->withBody($stream);
        }
        return $this->executeFrontendSubRequest($request);
    }

    /**
     * The headers every platform request carries.
     *
     * @return array<string, string>
     */
    protected static function headers(bool $idempotent = true): array
    {
        $headers = ['UCP-Agent' => self::PLATFORM, 'Request-Id' => self::uuid(), 'Content-Type' => 'application/json'];
        if ($idempotent) {
            $headers['Idempotency-Key'] = self::uuid();
        }
        return $headers;
    }

    protected static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-4%s-8%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $result = [];
        foreach ($decoded as $key => $value) {
            $result[(string)$key] = $value;
        }
        return $result;
    }

    /**
     * The body as the wire has it — objects stay objects, so an empty `{}`
     * is not mistaken for `[]` when it is validated against a schema.
     */
    protected static function wire(ResponseInterface $response): mixed
    {
        return json_decode((string)$response->getBody(), false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Every `data:` frame of a Server-Sent-Events body.
     *
     * @return list<array<string, mixed>>
     */
    protected static function events(ResponseInterface $response): array
    {
        $events = [];
        foreach (explode("\n\n", (string)$response->getBody()) as $block) {
            if (!str_starts_with($block, 'data: ')) {
                continue;
            }
            $decoded = json_decode(substr($block, 6), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $event = [];
            foreach ($decoded as $key => $value) {
                $event[(string)$key] = $value;
            }
            $events[] = $event;
        }
        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function trafficRows(): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(TrafficRepository::TABLE);
        $rows = $queryBuilder->select('*')->from(TrafficRepository::TABLE)->orderBy('uid')->executeQuery()->fetchAllAssociative();
        return array_values($rows);
    }
}

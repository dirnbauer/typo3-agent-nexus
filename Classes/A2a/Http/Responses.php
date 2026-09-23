<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use Webconsulting\AgentNexus\A2a\Protocol\Json;
use Webconsulting\AgentNexus\A2a\Protocol\ProtocolVersion;
use Webconsulting\AgentNexus\Shared\Http\EventStream;

/**
 * Response helpers shared by the A2A endpoints.
 */
final class Responses
{
    /** Pause between two stream frames, so a person watching sees the task work. */
    public const int STREAM_DELAY_MS = 45;

    /**
     * @param array<array-key, mixed> $payload
     * @param array<string, string> $headers
     */
    public static function json(array $payload, int $status = 200, string $contentType = 'application/json', array $headers = [], bool $pretty = false): ResponseInterface
    {
        $response = new Response('php://temp', $status, ['Content-Type' => $contentType . '; charset=utf-8'] + $headers);
        $body = $response->getBody();
        $body->write(Json::encode($payload, $pretty));
        $body->rewind();
        return $response;
    }

    /**
     * @param iterable<array<string, mixed>> $frames
     */
    public static function stream(iterable $frames): ResponseInterface
    {
        return EventStream::response($frames, 'a2a stream open', self::STREAM_DELAY_MS);
    }

    /**
     * The version the client asked for: the A2A-Version header, else the
     * A2A-Version query parameter, else nothing (which means 0.3).
     */
    public static function requestedVersion(ServerRequestInterface $request): string
    {
        $header = trim($request->getHeaderLine(ProtocolVersion::HEADER));
        if ($header !== '') {
            return $header;
        }
        $query = $request->getQueryParams()[ProtocolVersion::HEADER] ?? '';
        return is_string($query) ? trim($query) : '';
    }
}

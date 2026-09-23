<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * JSON responses of the UCP binding, encoded one way only — an idempotent
 * replay must return the stored bytes, so they are produced by the same flags
 * every time.
 */
final class JsonResponses
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $body, array $headers = []): ResponseInterface
    {
        return self::raw($status, self::encode($body), $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function raw(int $status, string $json, array $headers = []): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write($json);
        $stream->rewind();
        return new Response($stream, $status, ['Content-Type' => 'application/json; charset=utf-8'] + $headers);
    }

    /**
     * A protocol error: UCP's `{code, content}` body with a transport status
     * (400, 409, 422, 429, 503 …).
     *
     * @param array<string, string> $headers
     */
    public static function problem(int $status, string $code, string $content, array $headers = []): ResponseInterface
    {
        return self::json($status, ['code' => $code, 'content' => $content], $headers);
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public static function encode(array $body): string
    {
        return json_encode($body, self::FLAGS);
    }
}

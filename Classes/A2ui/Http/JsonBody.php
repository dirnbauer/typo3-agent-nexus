<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads the JSON object a request carries.
 *
 * The API runs before TYPO3 parses request bodies, and the backend AJAX
 * routes receive JSON too, so both read the raw body here, capped in size.
 */
final class JsonBody
{
    /**
     * @return array<string, mixed>
     * @throws A2uiProblem when the body is too large, not JSON or not a JSON object
     */
    public static function read(ServerRequestInterface $request, int $maxBytes): array
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $raw = '';
        while (!$stream->eof() && strlen($raw) <= $maxBytes) {
            $chunk = $stream->read(8192);
            if ($chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        if (strlen($raw) > $maxBytes) {
            throw new A2uiProblem(413, 'PAYLOAD_TOO_LARGE', sprintf('The request body is larger than %d bytes.', $maxBytes));
        }
        if (trim($raw) === '') {
            $parsed = $request->getParsedBody();
            if (is_array($parsed) && $parsed !== []) {
                return self::object($parsed);
            }
            throw A2uiProblem::badRequest('The request has no body. Send a JSON object.');
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw A2uiProblem::badRequest('The request body is not valid JSON.');
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw A2uiProblem::badRequest('The request body must be a JSON object.');
        }
        return self::object($decoded);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function object(array $data): array
    {
        $object = [];
        foreach ($data as $key => $value) {
            $object[(string)$key] = $value;
        }
        return $object;
    }
}

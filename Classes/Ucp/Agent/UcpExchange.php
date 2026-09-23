<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

/**
 * A UCP request the shopping agent sent and the response it received — what
 * the AG-UI stream shows as a tool call's arguments and result.
 */
final readonly class UcpExchange
{
    /**
     * @param array<string, string> $headers the request headers that carry protocol meaning
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $response the decoded response body
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $headers,
        public ?array $body,
        public int $status,
        public array $response,
    ) {}

    /**
     * The request as a tool call's arguments.
     *
     * @return array<string, mixed>
     */
    public function request(): array
    {
        $request = ['method' => $this->method, 'path' => $this->path, 'headers' => $this->headers];
        if ($this->body !== null) {
            $request['body'] = $this->body;
        }
        return $request;
    }

    /**
     * The response as a tool call's result.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function result(): array
    {
        return ['status' => $this->status, 'body' => $this->response];
    }

    /**
     * The checkout in the response, or an empty array when the response is
     * an error or a profile.
     *
     * @return array<string, mixed>
     */
    public function checkout(): array
    {
        return is_string($this->response['id'] ?? null) && is_string($this->response['status'] ?? null)
            ? $this->response
            : [];
    }

    /** The first error the business reported, for the narration. */
    public function firstError(): string
    {
        foreach (is_array($this->response['messages'] ?? null) ? $this->response['messages'] : [] as $message) {
            if (is_array($message) && ($message['type'] ?? null) === 'error' && is_string($message['content'] ?? null)) {
                return $message['content'];
            }
        }
        return is_string($this->response['content'] ?? null) ? $this->response['content'] : '';
    }
}

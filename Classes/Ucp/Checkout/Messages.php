<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

/**
 * UCP messages (schemas/common/types/message*.json): how the business tells
 * the platform what is wrong, what it must show and what is merely useful to
 * know. Errors carry a severity that decides what the platform does next;
 * warnings must be shown; info may be.
 */
final class Messages
{
    public const string SEVERITY_RECOVERABLE = 'recoverable';
    public const string SEVERITY_BUYER_INPUT = 'requires_buyer_input';
    public const string SEVERITY_BUYER_REVIEW = 'requires_buyer_review';
    public const string SEVERITY_UNRECOVERABLE = 'unrecoverable';

    /**
     * @return array{type: 'error', code: string, content: string, severity: string, path?: string}
     */
    public static function error(string $code, string $content, string $severity = self::SEVERITY_RECOVERABLE, string $path = ''): array
    {
        $message = ['type' => 'error', 'code' => $code, 'content' => $content, 'severity' => $severity];
        if ($path !== '') {
            $message['path'] = $path;
        }
        return $message;
    }

    /**
     * @return array{type: 'warning', code: string, content: string, path?: string}
     */
    public static function warning(string $code, string $content, string $path = ''): array
    {
        $message = ['type' => 'warning', 'code' => $code, 'content' => $content];
        if ($path !== '') {
            $message['path'] = $path;
        }
        return $message;
    }

    /**
     * @return array{type: 'info', content: string, code?: string, path?: string}
     */
    public static function info(string $content, string $code = '', string $path = ''): array
    {
        $message = ['type' => 'info', 'content' => $content];
        if ($code !== '') {
            $message['code'] = $code;
        }
        if ($path !== '') {
            $message['path'] = $path;
        }
        return $message;
    }

    /**
     * Errors first, then warnings, then information: platforms work through
     * errors as a prioritised stack.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    public static function ordered(array $messages): array
    {
        $rank = static fn(array $message): int => match ($message['type'] ?? '') {
            'error' => 0,
            'warning' => 1,
            default => 2,
        };
        usort($messages, static fn(array $a, array $b): int => $rank($a) <=> $rank($b));
        return $messages;
    }
}

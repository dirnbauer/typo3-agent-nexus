<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

/**
 * An RFC 8941 Dictionary parser — the syntax of the UCP-Agent header
 * (`profile="https://platform.example/.well-known/ucp"`).
 *
 * It follows the parsing algorithm of RFC 8941 section 4.2 for dictionaries,
 * items, inner lists and parameters. Bare items become PHP values: strings and
 * tokens as strings, integers and decimals as int and float, booleans as bool,
 * byte sequences as their decoded bytes. Anything the grammar does not allow
 * throws {@see \InvalidArgumentException}, and the whole field is rejected, as
 * the RFC requires.
 */
final class StructuredField
{
    private string $input;
    private int $position = 0;

    private function __construct(string $input)
    {
        $this->input = $input;
    }

    /**
     * @return array<string, array{value: mixed, params: array<string, mixed>}>
     * @throws \InvalidArgumentException
     */
    public static function parseDictionary(string $field): array
    {
        $parser = new self(trim($field, ' '));
        $dictionary = [];
        while (!$parser->atEnd()) {
            $key = $parser->key();
            if ($parser->peek() === '=') {
                $parser->position++;
                $dictionary[$key] = $parser->itemOrInnerList();
            } else {
                $dictionary[$key] = ['value' => true, 'params' => $parser->parameters()];
            }
            $parser->skipWhitespace();
            if ($parser->atEnd()) {
                break;
            }
            $parser->expect(',');
            $parser->skipWhitespace();
            if ($parser->atEnd()) {
                throw new \InvalidArgumentException('A dictionary must not end with a comma.', 1758700201);
            }
        }
        return $dictionary;
    }

    /**
     * @return array{value: mixed, params: array<string, mixed>}
     */
    private function itemOrInnerList(): array
    {
        if ($this->peek() === '(') {
            $this->position++;
            $items = [];
            while (true) {
                $this->skipSpaces();
                if ($this->peek() === ')') {
                    $this->position++;
                    return ['value' => $items, 'params' => $this->parameters()];
                }
                $items[] = ['value' => $this->bareItem(), 'params' => $this->parameters()];
                $next = $this->peek();
                if ($next !== ' ' && $next !== ')') {
                    throw new \InvalidArgumentException('An inner list is not closed.', 1758700202);
                }
            }
        }
        return ['value' => $this->bareItem(), 'params' => $this->parameters()];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(): array
    {
        $parameters = [];
        while ($this->peek() === ';') {
            $this->position++;
            $this->skipSpaces();
            $key = $this->key();
            $value = true;
            if ($this->peek() === '=') {
                $this->position++;
                $value = $this->bareItem();
            }
            $parameters[$key] = $value;
        }
        return $parameters;
    }

    private function key(): string
    {
        $first = $this->peek();
        if ($first === null || preg_match('/^[a-z*]$/', $first) !== 1) {
            throw new \InvalidArgumentException('A key must start with a lower-case letter or "*".', 1758700203);
        }
        $key = '';
        while (($char = $this->peek()) !== null && preg_match('/^[a-z0-9_\-.*]$/', $char) === 1) {
            $key .= $char;
            $this->position++;
        }
        return $key;
    }

    private function bareItem(): mixed
    {
        $char = $this->peek();
        if ($char === null) {
            throw new \InvalidArgumentException('A value is missing.', 1758700204);
        }
        return match (true) {
            $char === '"' => $this->string(),
            $char === ':' => $this->byteSequence(),
            $char === '?' => $this->boolean(),
            $char === '-' || ctype_digit($char) => $this->number(),
            $char === '*' || ctype_alpha($char) => $this->token(),
            default => throw new \InvalidArgumentException(sprintf('Unexpected character "%s".', $char), 1758700205),
        };
    }

    private function string(): string
    {
        $this->position++;
        $value = '';
        while (($char = $this->peek()) !== null) {
            $this->position++;
            if ($char === '\\') {
                $next = $this->peek();
                if ($next !== '"' && $next !== '\\') {
                    throw new \InvalidArgumentException('A string may only escape a quote or a backslash.', 1758700206);
                }
                $value .= $next;
                $this->position++;
                continue;
            }
            if ($char === '"') {
                return $value;
            }
            $ord = ord($char);
            if ($ord < 0x20 || $ord > 0x7e) {
                throw new \InvalidArgumentException('A string may only contain printable ASCII.', 1758700207);
            }
            $value .= $char;
        }
        throw new \InvalidArgumentException('A string is not closed.', 1758700208);
    }

    private function token(): string
    {
        $token = '';
        while (($char = $this->peek()) !== null && preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z:\/]$/', $char) === 1) {
            $token .= $char;
            $this->position++;
        }
        return $token;
    }

    private function byteSequence(): string
    {
        $this->position++;
        $end = strpos($this->input, ':', $this->position);
        if ($end === false) {
            throw new \InvalidArgumentException('A byte sequence is not closed.', 1758700209);
        }
        $encoded = substr($this->input, $this->position, $end - $this->position);
        $this->position = $end + 1;
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('A byte sequence is not valid base64.', 1758700210);
        }
        return $decoded;
    }

    private function boolean(): bool
    {
        $this->position++;
        $char = $this->peek();
        $this->position++;
        return match ($char) {
            '1' => true,
            '0' => false,
            default => throw new \InvalidArgumentException('A boolean is "?1" or "?0".', 1758700211),
        };
    }

    private function number(): int|float
    {
        if (preg_match('/\G(-?)(\d{1,15})(?:\.(\d{1,3}))?/', $this->input, $matches, 0, $this->position) !== 1) {
            throw new \InvalidArgumentException('A number is malformed.', 1758700212);
        }
        $this->position += strlen($matches[0]);
        if (isset($matches[3])) {
            if (strlen($matches[2]) > 12) {
                throw new \InvalidArgumentException('A decimal has too many digits.', 1758700213);
            }
            return (float)$matches[0];
        }
        return (int)$matches[0];
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            throw new \InvalidArgumentException(sprintf('Expected "%s".', $char), 1758700214);
        }
        $this->position++;
    }

    private function skipWhitespace(): void
    {
        while (($char = $this->peek()) === ' ' || $char === "\t") {
            $this->position++;
        }
    }

    private function skipSpaces(): void
    {
        while ($this->peek() === ' ') {
            $this->position++;
        }
    }

    private function peek(): ?string
    {
        return $this->position < strlen($this->input) ? $this->input[$this->position] : null;
    }

    private function atEnd(): bool
    {
        return $this->position >= strlen($this->input);
    }
}

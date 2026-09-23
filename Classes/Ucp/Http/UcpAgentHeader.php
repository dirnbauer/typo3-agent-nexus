<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The UCP-Agent request header: who is calling, as the URL of its profile.
 *
 *     UCP-Agent: profile="https://platform.example/.well-known/ucp"
 *
 * Every UCP request carries it, in RFC 8941 Dictionary syntax. A production
 * business fetches the profile behind the URL and negotiates capabilities with
 * it. This sandbox only checks the syntax: it never fetches a URL a caller
 * names (a public demo that did would be an open SSRF relay), so it answers
 * with its full capability set and says so in an `info` message.
 *
 * A `version` parameter on the profile member (or a `version` member) that
 * names another UCP version is answered with `version_unsupported`.
 */
final readonly class UcpAgentHeader
{
    public const string NAME = 'UCP-Agent';

    public function __construct(
        public string $profile,
        public string $version = '',
    ) {}

    /**
     * @throws InvalidUcpAgent
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        return self::parse($request->getHeaderLine(self::NAME));
    }

    /**
     * @throws InvalidUcpAgent
     */
    public static function parse(string $value): self
    {
        if (trim($value) === '') {
            throw new InvalidUcpAgent('Send a UCP-Agent header: profile="https://…" with the URL of your platform profile.', 1758700301);
        }
        if (strlen($value) > 2048) {
            throw new InvalidUcpAgent('The UCP-Agent header is too long.', 1758700302);
        }
        try {
            $dictionary = StructuredField::parseDictionary($value);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidUcpAgent('The UCP-Agent header is not an RFC 8941 dictionary: ' . $e->getMessage(), 1758700303, $e);
        }
        $profile = $dictionary['profile']['value'] ?? null;
        if (!is_string($profile)) {
            throw new InvalidUcpAgent('The UCP-Agent header needs a profile member with a quoted URL: profile="https://…".', 1758700304);
        }
        if (!self::isProfileUrl($profile)) {
            throw new InvalidUcpAgent('The profile URL must be an absolute https URL without credentials or a fragment.', 1758700305);
        }
        $version = $dictionary['profile']['params']['version'] ?? ($dictionary['version']['value'] ?? '');

        return new self($profile, is_string($version) ? $version : '');
    }

    /** The header value that names a profile. */
    public static function forProfile(string $profileUrl): string
    {
        return 'profile="' . addcslashes($profileUrl, '"\\') . '"';
    }

    private static function isProfileUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        // Plain http only for a platform on this machine, as in local development.
        return $scheme === 'http' && in_array(strtolower(trim($parts['host'], '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

/**
 * The UCP-Agent header is missing, not an RFC 8941 dictionary or names no
 * usable profile URL — answered with 400 `invalid_profile_url`.
 */
final class InvalidUcpAgent extends \RuntimeException {}

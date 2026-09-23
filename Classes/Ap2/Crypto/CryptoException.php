<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * A token, key or signature that cannot be used: malformed input, an algorithm
 * this implementation refuses, a signature that does not verify.
 *
 * Verifiers catch it and turn the message into a failed check; nothing in the
 * AP2 layer lets it reach a visitor unexplained.
 */
final class CryptoException extends \RuntimeException {}

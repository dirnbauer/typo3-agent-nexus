<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Service\Jwt;

final class JwtTest extends UnitTestCase
{
    private Jwt $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new Jwt();
    }

    #[Test]
    public function signedTokenVerifiesAndRoundTripsItsClaims(): void
    {
        $token = $this->subject->sign(['typ' => 'IntentMandate', 'sub' => 'agent:shopping']);

        $result = $this->subject->verify($token);

        self::assertTrue($result['valid']);
        self::assertSame('IntentMandate', $result['claims']['typ']);
        self::assertSame('agent:shopping', $result['claims']['sub']);
        self::assertSame('HS256', $result['header']['alg']);
    }

    #[Test]
    public function tamperedPayloadBreaksTheSignature(): void
    {
        $token = $this->subject->sign(['totalCents' => 1000]);
        [$header, , $signature] = explode('.', $token);
        $forged = $this->subject->b64UrlEncode((string)json_encode(['totalCents' => 1]));

        $result = $this->subject->verify($header . '.' . $forged . '.' . $signature);

        self::assertFalse($result['valid']);
        self::assertSame('Signature does not verify.', $result['reason']);
    }

    #[Test]
    public function expiredTokenIsRejectedEvenThoughItsSignatureIsIntact(): void
    {
        $token = $this->subject->sign(['exp' => time() - 1]);

        $result = $this->subject->verify($token);

        self::assertFalse($result['valid']);
        self::assertSame('Token has expired.', $result['reason']);
    }

    #[Test]
    public function tokenThatIsNotYetValidIsRejected(): void
    {
        $result = $this->subject->verify($this->subject->sign(['nbf' => time() + 600]));

        self::assertFalse($result['valid']);
        self::assertSame('Token not yet valid.', $result['reason']);
    }

    #[Test]
    public function malformedTokenIsRejectedWithoutThrowing(): void
    {
        $result = $this->subject->verify('not-a-token');

        self::assertFalse($result['valid']);
        self::assertSame('Malformed token.', $result['reason']);
    }
}

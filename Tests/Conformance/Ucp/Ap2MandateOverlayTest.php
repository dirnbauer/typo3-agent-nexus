<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ucp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Service\UcpMandateBridge;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;
use Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures\Sandbox;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\FixedExtensionConfiguration;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutService;
use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;

/**
 * The known conflict between UCP 2026-08-25 and AP2 v0.2.0, and how the local
 * overlay resolves it (Documentation/Protocols/KnownSpecConflicts.rst).
 *
 * UCP carries an AP2 mandate in `ap2.checkout_mandate`, but its schema only
 * admits a JWS followed by tilde-separated segments without dots. Every AP2
 * mandate ends with a tilde, and a delegated one chains a KB-SD-JWT (with dots)
 * behind "~~", so the official schema rejects AP2's own examples. The overlay
 * accepts the upstream form and the AP2 form; everything else still fails.
 */
final class Ap2MandateOverlayTest extends ConformanceTestCase
{
    private const string UPSTREAM = 'https://ucp.dev/schemas/common/payment_ap2_mandate.json';
    private const string OVERLAY = 'https://raw.githubusercontent.com/dirnbauer/typo3-agent-nexus/main/Resources/Private/Schemas/Overlays/ucp/2026-08-25/common/payment_ap2_mandate.json';
    private const string UPSTREAM_FILE = 'ucp/2026-08-25/schemas/common/payment_ap2_mandate.json';
    private const string OVERLAY_FILE = 'ucp/2026-08-25/common/payment_ap2_mandate.json';

    /** The pattern of `$defs.checkout_mandate` in UCP 2026-08-25, verbatim. */
    private const string UPSTREAM_PATTERN = '^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+(~[A-Za-z0-9_-]+)*$';

    /** The shortened chain Documentation/Protocols/KnownSpecConflicts.rst shows. */
    private const string DOCUMENTED_EXAMPLE = 'eyJhbGciOiJFUzI1NiJ9.eyJfc2QiOlsiLi4uIl19.c2lnbmF0dXJlLTE~WyJzYWx0LTEiLCJtZXJjaGFudCIsIkV4YW1wbGUgU2hvcCJd~~eyJhbGciOiJFUzI1NiIsInR5cCI6ImtiK3NkLWp3dCJ9.eyJzZF9oYXNoIjoiLi4uIn0.c2lnbmF0dXJlLTI~WyJzYWx0LTIiLCJjaGVja291dF9oYXNoIiwiLi4uIl0~';

    /** A token of exactly the shape the upstream pattern describes. */
    private const string UPSTREAM_TOKEN = 'eyJhbGciOiJFUzI1NiIsInR5cCI6ImtiK2p3dCJ9.eyJzZF9oYXNoIjoiYWJjIn0.c2lnbmF0dXJl~WyJzYWx0IiwibmFtZSIsIkFkYSJd~a2Itand0';

    #[Test]
    public function theOfficialSchemaIsVendoredUnchanged(): void
    {
        $source = (string)file_get_contents(SchemaValidator::schemaDirectory() . '/ucp/SOURCE.txt');
        $hash = hash_file('sha256', SchemaValidator::schemaDirectory() . '/' . self::UPSTREAM_FILE);

        self::assertStringContainsString($hash . '  2026-08-25/schemas/common/payment_ap2_mandate.json', $source, 'The overlay resolves the conflict; the official file stays as published.');
        self::assertSame(self::UPSTREAM_PATTERN, $this->definition(SchemaValidator::schemaDirectory() . '/' . self::UPSTREAM_FILE, 'checkout_mandate')['pattern'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function officialAp2Examples(): array
    {
        $examples = [];
        foreach (glob(dirname(__DIR__) . '/Ap2/Fixtures/*.sdjwt.txt') ?: [] as $file) {
            $examples[basename($file, '.sdjwt.txt')] = [trim((string)file_get_contents($file))];
        }
        return $examples;
    }

    /**
     * When this fails, UCP fixed its pattern: drop the overlay and this test.
     */
    #[Test]
    #[DataProvider('officialAp2Examples')]
    public function theOfficialSchemaRejectsAp2sOwnExamples(string $token): void
    {
        self::assertViolates(self::UPSTREAM . '#/$defs/checkout_mandate', $token);
    }

    #[Test]
    #[DataProvider('officialAp2Examples')]
    public function theOverlayAcceptsEveryOfficialAp2Example(string $token): void
    {
        self::assertConformsTo(self::OVERLAY . '#/$defs/checkout_mandate', $token);
    }

    #[Test]
    public function theOverlayStillAcceptsWhatTheOfficialSchemaAccepts(): void
    {
        self::assertConformsTo(self::UPSTREAM . '#/$defs/checkout_mandate', self::UPSTREAM_TOKEN);
        self::assertConformsTo(self::OVERLAY . '#/$defs/checkout_mandate', self::UPSTREAM_TOKEN);
    }

    #[Test]
    public function theMandateChainThisSandboxSignsPassesTheOverlayOnly(): void
    {
        $chain = $this->sandboxChain();

        self::assertStringContainsString('~~', $chain, 'A delegated mandate: the open token, then the closing KB-SD-JWT.');
        self::assertViolates(self::UPSTREAM . '#/$defs/checkout_mandate', $chain);
        self::assertConformsTo(self::OVERLAY . '#/$defs/checkout_mandate', $chain);
    }

    /**
     * @return array<string, array{0: string|int}>
     */
    public static function garbage(): array
    {
        return [
            'empty' => [''],
            'plain words' => ['not a mandate'],
            'two segments' => ['eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ'],
            'no signature' => ['eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.~'],
            'starts with a tilde' => ['~eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.c2ln~'],
            'three tildes' => ['eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.c2ln~~~'],
            'chain ending in the separator' => ['eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.c2ln~ZA~~'],
            'second token without its tilde' => ['eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.c2ln~~eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.c2ln'],
            'a space inside' => ['eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.c2ln~Z A~'],
            'standard base64' => ['eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.c2ln+/==~'],
            'a disclosure with a dot' => ['eyJhbGciOiJFUzI1NiJ9.eyJhIjoxfQ.c2ln~a.b~'],
            'not a string' => [42],
        ];
    }

    #[Test]
    #[DataProvider('garbage')]
    public function garbageFailsBothSchemas(string|int $token): void
    {
        self::assertViolates(self::UPSTREAM . '#/$defs/checkout_mandate', $token);
        self::assertViolates(self::OVERLAY . '#/$defs/checkout_mandate', $token);
    }

    #[Test]
    public function aWholeCheckoutWithAnAp2ChainConformsToTheOverlayComposition(): void
    {
        $sandbox = new Sandbox();
        $bridge = new UcpMandateBridge($sandbox->keys, $sandbox->merchant, $sandbox->credentialProvider);
        $checkout = $bridge->withAuthorization($this->readyCheckout());
        $checkout['ap2']['checkout_mandate'] = $this->sandboxChain();

        self::assertConformsTo('https://ucp.dev/schemas/shopping/checkout.json', $checkout, 'The checkout itself is a UCP checkout.');
        self::assertViolates(self::UPSTREAM . '#/$defs/dev.ucp.shopping.checkout', $checkout);
        self::assertConformsTo(self::OVERLAY . '#/$defs/dev.ucp.shopping.checkout', $checkout);

        $checkout['ap2']['merchant_authorization'] = 'not a detached JWS';
        self::assertViolates(self::OVERLAY . '#/$defs/dev.ucp.shopping.checkout', $checkout, 'The overlay keeps every other upstream rule.');
    }

    #[Test]
    public function theDocumentationStatesTheExactPatterns(): void
    {
        $documentation = (string)file_get_contents(dirname(__DIR__, 3) . '/Documentation/Protocols/KnownSpecConflicts.rst');
        $overlay = $this->definition(SchemaValidator::overlayDirectory() . '/' . self::OVERLAY_FILE, 'ap2_mandate_chain');

        self::assertStringContainsString(self::UPSTREAM_PATTERN, $documentation);
        self::assertIsString($overlay['pattern'] ?? null);
        self::assertStringContainsString($overlay['pattern'], $documentation);
        self::assertStringContainsString(self::OVERLAY, $documentation);
        self::assertStringContainsString(self::DOCUMENTED_EXAMPLE, $documentation);
        self::assertViolates(self::UPSTREAM . '#/$defs/checkout_mandate', self::DOCUMENTED_EXAMPLE, 'The documented example shows the conflict.');
        self::assertConformsTo(self::OVERLAY . '#/$defs/checkout_mandate', self::DOCUMENTED_EXAMPLE);
    }

    /**
     * A closed checkout mandate chained to its open one, as this
     * installation's AP2 sandbox signs it.
     */
    private function sandboxChain(): string
    {
        $sandbox = new Sandbox();
        $now = time();
        $open = $sandbox->trustedSurface->sign(MandateContent::openCheckout(
            [['id' => 'licence', 'acceptable' => [['id' => 'pro-license', 'title' => 'Desiderio Pro Licence']], 'quantity' => 1]],
            [Parties::MERCHANT],
            $sandbox->agent->publicJwk(),
            $now,
            $now + 600,
        ));
        return $sandbox->agent->close(
            $open,
            MandateContent::closedCheckout('eyJhbGciOiJFUzI1NiJ9.eyJpZCI6ImNoa18xIn0.c2ln', 'aGFzaA', $now, $now + 600),
            MandateType::Checkout->audience(),
            $sandbox->merchant->challenge(),
            $now,
        )->serialize();
    }

    /**
     * @return array<string, mixed>
     */
    private function readyCheckout(): array
    {
        $service = new CheckoutService(new Merchant(), new SandboxPaymentHandler(), new ExtensionSettings(new FixedExtensionConfiguration([])));
        return $service->create(
            ['line_items' => [['item' => ['id' => 'pro-license'], 'quantity' => 1]], 'buyer' => ['email' => 'ada@example.org']],
            'chk_0123456789abcdef01234567',
            new \DateTimeImmutable('2026-09-23T10:00:00Z'),
        )->body;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $file, string $name): array
    {
        $schema = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        $definition = $schema['$defs'][$name] ?? null;
        self::assertIsArray($definition, $name . ' is not defined in ' . basename($file));
        $normalised = [];
        foreach ($definition as $key => $value) {
            $normalised[(string)$key] = $value;
        }
        return $normalised;
    }
}

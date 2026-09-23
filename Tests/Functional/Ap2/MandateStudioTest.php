<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Ap2;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Stream;
use Webconsulting\AgentNexus\Ap2\Controller\MandateController;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Sandbox\Artefact;
use Webconsulting\AgentNexus\Ap2\Sandbox\AutonomousFlow;
use Webconsulting\AgentNexus\Ap2\Sandbox\RecordingContext;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Tests\Functional\Backend\AbstractBackendModuleTestCase;

/**
 * The mandate studio's two AJAX endpoints as an admin uses them: sign a
 * mandate from the form, and verify what was pasted.
 */
final class MandateStudioTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function anOpenCheckoutMandateIsSignedRecordedAndLinkedToTheInspector(): void
    {
        $body = $this->ok($this->mint(['type' => 'open_checkout', 'items' => ['pro-license', 'onboarding-addon'], 'merchant' => 'desiderio-store', 'expires' => '60']));

        $artefact = Json::map($body['artefact']);
        self::assertSame('mandate.checkout.open.1', Json::map($artefact['claims'] ?? null)['vct'] ?? null);
        self::assertSame('trusted-surface', $artefact['role'] ?? null);
        self::assertSame($artefact['reference'] ?? null, $body['chainId'], 'An open checkout mandate starts its own chain.');
        self::assertCount(4, Json::list($artefact['disclosures'] ?? null), 'The two items, the merchant and the mandate itself are each a disclosure.');
        self::assertStringContainsString('uid=', Json::string($body['inspectorUri']));

        $object = $this->get(ObjectStore::class)->find(ObjectKind::Mandate, Json::string($artefact['reference'] ?? null));
        self::assertNotNull($object);
        self::assertSame('backend', $object->source);
        self::assertSame(1, $object->beUser);
    }

    #[Test]
    public function anOpenPaymentMandateIsTiedToTheOpenCheckoutMandatePastedWithIt(): void
    {
        $checkout = Json::map($this->ok($this->mint(['type' => 'open_checkout', 'items' => ['support-pack']]))['artefact']);
        $openCheckout = DelegateChain::parse(Json::string($checkout['token'] ?? null));

        $body = $this->ok($this->mint(['type' => 'open_payment', 'cap' => '499,90', 'linked' => Json::string($checkout['token'] ?? null)]));

        $claims = Artefact::mandateOf(DelegateChain::parse(Json::string(Json::map($body['artefact'])['token'] ?? null))->root());
        $constraints = Json::objects($claims['constraints'] ?? null);
        self::assertSame(49990, array_column($constraints, 'max', 'type')['payment.amount_range'] ?? null, 'Euros with a decimal comma become cents.');
        self::assertSame($openCheckout->root()->sdHash(), array_column($constraints, 'conditional_transaction_id', 'type')['payment.reference'] ?? null);
        self::assertSame($checkout['reference'] ?? null, $body['chainId'], 'Both belong to the same chain.');
    }

    #[Test]
    public function formMistakesComeBackAs422WithTheFieldToFix(): void
    {
        foreach ([
            [['type' => 'intent_mandate'], 'type'],
            [['type' => 'open_checkout', 'items' => []], 'items'],
            [['type' => 'open_checkout', 'items' => ['pro-license'], 'expires' => '0'], 'expires'],
            [['type' => 'open_payment', 'cap' => 'lots'], 'cap'],
            [['type' => 'open_payment', 'cap' => '500'], 'linkedCheckout'],
            [['type' => 'payment', 'signer' => 'shopping-agent', 'linked' => 'not-a-mandate~'], 'linkedInvalid'],
        ] as [$input, $key]) {
            $response = $this->mint($input);
            self::assertSame(422, $response->getStatusCode(), (string)json_encode($input));
            $error = Json::map($this->json($response)['error'] ?? null);
            self::assertSame($key, $error['key'] ?? null, (string)json_encode($input));
            self::assertNotSame('', Json::string($error['message'] ?? null));
        }
    }

    #[Test]
    public function verifyingAfterThePurchaseShowsTheMandateWasUsed(): void
    {
        $flow = $this->get(AutonomousFlow::class)->run(50000, AutonomousFlow::WITHIN, 'desiderio-store', new RecordingContext(Channel::Api));
        $artefacts = Json::map($flow['artefacts']);
        $receipts = Json::map($flow['receipts']);
        $closedCheckout = Json::map($artefacts['closedCheckoutMandate'] ?? null);

        $results = Json::objects($this->ok($this->verify(Json::string($closedCheckout['token'] ?? null) . "\n" . Json::string(Json::map($receipts['checkout'] ?? null)['token'] ?? null)))['results'] ?? null);

        self::assertCount(2, $results);
        [$mandate, $receipt] = $results;
        self::assertSame('mandate.checkout.1', $mandate['kind']);
        self::assertFalse($mandate['valid'], 'A verified mandate cannot be presented again.');
        $failed = array_values(array_filter(Json::objects($mandate['checks'] ?? null), static fn(array $check): bool => $check['pass'] === false));
        self::assertSame(['single_use'], array_column($failed, 'id'));
        self::assertStringContainsString('uid=', Json::string($mandate['inspectorUri'] ?? null));

        self::assertSame('checkout_receipt', $receipt['kind']);
        self::assertTrue($receipt['valid']);
    }

    #[Test]
    public function verifyingNeedsSomethingToVerifyAndNotTooMuch(): void
    {
        self::assertSame('empty', Json::map($this->json($this->verify("  \n "))['error'] ?? null)['key'] ?? null);
        self::assertSame('tooMany', Json::map($this->json($this->verify(implode(' ', array_fill(0, 5, 'a.b.c'))))['error'] ?? null)['key'] ?? null);
    }

    #[Test]
    public function aForgedTokenIsReportedNotThrown(): void
    {
        $results = Json::objects($this->ok($this->verify('eyJhbGciOiJub25lIn0.e30.~'))['results'] ?? null);

        self::assertCount(1, $results);
        self::assertFalse($results[0]['valid']);
        self::assertSame('format', Json::objects($results[0]['checks'] ?? null)[0]['id'] ?? null);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function mint(array $input): ResponseInterface
    {
        return $this->get(MandateController::class)->mint($this->ajax('agentnexus_ap2_mint', $input));
    }

    private function verify(string $token): ResponseInterface
    {
        return $this->get(MandateController::class)->verify($this->ajax('agentnexus_ap2_verify', ['token' => $token]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function ajax(string $route, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write((string)json_encode($body));
        $stream->rewind();
        return $this->moduleRequest('agentnexus_ap2_studio')
            ->withMethod('POST')
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json')
            ->withAttribute('agentnexus.ajaxRoute', $route);
    }

    /**
     * @return array<string, mixed>
     */
    private function ok(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        return $this->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return Json::map($data);
    }
}

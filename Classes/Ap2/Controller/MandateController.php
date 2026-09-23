<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Sandbox\RecordingContext;
use Webconsulting\AgentNexus\Ap2\Service\MandateService;
use Webconsulting\AgentNexus\Ap2\Service\StudioException;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * The mandate studio's backend endpoints: sign a mandate from the form
 * (`agentnexus_ap2_mint`) and verify pasted tokens (`agentnexus_ap2_verify`).
 *
 * Both answer JSON. Anything the form got wrong comes back as 422 with a
 * label key the studio translates.
 */
#[Autoconfigure(public: true)]
final readonly class MandateController
{
    public function __construct(
        private MandateService $mandates,
        private ObjectStore $objects,
        private UriBuilder $uriBuilder,
    ) {}

    public function mint(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $result = $this->mandates->mint(self::body($request), new RecordingContext(Channel::Backend, 0, self::backendUser()));
        } catch (StudioException $e) {
            return self::error($e);
        }
        $reference = Json::string($result['artefact']['reference'] ?? null);
        self::capture($request)?->correlate($reference);
        return new JsonResponse($result + [
            'inspectorUri' => $this->inspectorUri($reference),
            'relatedInspectorUris' => array_map(
                fn(array $related): string => $this->inspectorUri(Json::string($related['reference'] ?? null)),
                $result['related'],
            ),
        ]);
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $results = $this->mandates->verify(Json::string(self::body($request)['token'] ?? null));
        } catch (StudioException $e) {
            return self::error($e);
        }
        self::capture($request)?->correlate(Json::string($results[0]['reference'] ?? null));
        foreach ($results as $index => $result) {
            $results[$index]['inspectorUri'] = $this->inspectorUri(Json::string($result['reference'] ?? null));
        }
        return new JsonResponse(['results' => $results]);
    }

    private function inspectorUri(string $reference): string
    {
        $object = $reference === '' ? null : $this->objects->find(ObjectKind::Mandate, $reference);
        if ($object === null) {
            return '';
        }
        return (string)$this->uriBuilder->buildUriFromRoute(ObjectKind::Mandate->inspectorModule() . '.detail', ['uid' => $object->uid]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return Json::map($parsed);
        }
        $raw = (string)$request->getBody();
        try {
            return $raw === '' ? [] : Json::decodeObject($raw);
        } catch (CryptoException) {
            return [];
        }
    }

    private static function capture(ServerRequestInterface $request): ?TrafficCapture
    {
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        return $capture instanceof TrafficCapture ? $capture : null;
    }

    private static function backendUser(): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        return $user instanceof BackendUserAuthentication && is_numeric($user->user['uid'] ?? null) ? (int)$user->user['uid'] : 0;
    }

    private static function error(StudioException $e): JsonResponse
    {
        return new JsonResponse(['error' => ['key' => $e->key, 'message' => $e->getMessage()]], 422);
    }
}

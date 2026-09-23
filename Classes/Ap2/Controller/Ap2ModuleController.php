<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Ap2\Http\Ap2Routes;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\ConstraintType;
use Webconsulting\AgentNexus\Ap2\Mandate\ErrorCode;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateSchema;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\Money;
use Webconsulting\AgentNexus\Ap2\Sandbox\DemoCatalogue;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\MandateRecorder;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Ap2\Service\UcpVerdict;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * The AP2 screens of the Agent Nexus module.
 *
 *  - Mandate studio: sign any of the four mandate types, verify a pasted
 *    mandate, chain or receipt, run the whole autonomous flow, see the keys
 *    and the latest mandates.
 *  - Mandate reference: the v0.2 types, their fields, the constraints, the
 *    roles, the checks and the error codes — generated from the same
 *    catalogue the verifiers use.
 */
#[AsController]
final readonly class Ap2ModuleController
{
    public const string LANGUAGE_DOMAIN = 'agent_nexus.ap2';
    private const string STYLESHEET = 'EXT:agent_nexus/Resources/Public/Css/modules/ap2.css';
    private const int RECENT = 10;

    public function __construct(
        private ModuleFrame $moduleFrame,
        private ObjectStore $objects,
        private RouteRegistry $routes,
        private KeyRing $keys,
        private DemoCatalogue $catalogue,
        private SiteLocator $siteLocator,
        private UriBuilder $uriBuilder,
    ) {}

    public function studioAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleFrame->create($request, [self::STYLESHEET], ['@webconsulting/agent-nexus/ap2-studio.js']);
        $origin = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();

        $recent = array_map($this->row(...), $this->objects->list(new ObjectFilter(ObjectKind::Mandate), self::RECENT));
        $view->assignMultiple([
            'types' => array_map(static fn(MandateType $type): array => ['key' => $type->key(), 'vct' => $type->value, 'open' => $type->isOpen()], MandateType::cases()),
            'merchants' => Parties::merchants(),
            'items' => array_map(static fn(array $item): array => $item + ['priceText' => Money::format($item['price'])], $this->catalogue->items()),
            'keys' => $this->keys(),
            'stats' => [
                'issued' => $this->objects->count(new ObjectFilter(ObjectKind::Mandate, MandateRecorder::STATE_ISSUED)),
                'verified' => $this->objects->count(new ObjectFilter(ObjectKind::Mandate, MandateRecorder::STATE_VERIFIED)),
                'rejected' => $this->objects->count(new ObjectFilter(ObjectKind::Mandate, MandateRecorder::STATE_REJECTED)),
            ],
            'recent' => $recent,
            'jwksUrl' => $this->routes->url(Ap2Routes::JWKS, $origin),
            'authorizeUrl' => $this->routes->url(Ap2Routes::AUTHORIZE, $origin),
            'inspectorUri' => (string)$this->uriBuilder->buildUriFromRoute(ObjectKind::Mandate->inspectorModule()),
            'frontendUrl' => $this->siteLocator->protocolUrl('ap2') ?? '',
            'defaultCap' => '500',
        ]);

        return $view->renderResponse('Ap2/Studio');
    }

    public function referenceAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleFrame->create($request, [self::STYLESHEET]);

        $view->assignMultiple([
            'mandates' => array_map(static fn(MandateType $type): array => [
                'vct' => $type->value,
                'key' => $type->key(),
                'open' => $type->isOpen(),
                'audience' => $type->audience(),
                'schemaId' => $type->schemaId(),
                'fields' => MandateSchema::fields($type),
                'constraints' => array_map(static fn(ConstraintType $constraint): string => $constraint->value, $type->isOpen() ? ConstraintType::forMandate($type) : []),
            ], MandateType::cases()),
            'constraints' => array_map(static fn(ConstraintType $constraint): array => [
                'type' => $constraint->value,
                'key' => $constraint->key(),
                'mandate' => $constraint->mandate()->value,
                'fields' => MandateSchema::constraintFields($constraint),
                'check' => $constraint->check()->value,
            ], ConstraintType::cases()),
            'sharedTypes' => MandateSchema::types(),
            'receipts' => MandateSchema::receipts(),
            'roles' => $this->keys(),
            'checks' => array_map(static fn(CheckType $check): array => [
                'id' => $check->value,
                'failure' => $check->failure()->value,
            ], CheckType::cases()),
            'errors' => array_map(static fn(ErrorCode $error): array => ['code' => $error->value, 'terminal' => $error->isTerminal()], ErrorCode::cases()),
            'ucpErrors' => array_keys(UcpVerdict::CODES),
        ]);

        return $view->renderResponse('Ap2/Reference');
    }

    /**
     * @return list<array{role: string, kid: string}>
     */
    private function keys(): array
    {
        return array_map(fn(Role $role): array => [
            'role' => $role->value,
            'kid' => $this->keys->signer($role)->kid,
        ], Role::cases());
    }

    /**
     * @return array{uid: int, label: string, kind: string, role: string, state: string, stateBadge: string, updated: int, detailUri: string}
     */
    private function row(ProtocolObject $object): array
    {
        $kind = $object->payload['vct'] ?? ($object->payload['type'] ?? '');
        $role = $object->payload['role'] ?? '';
        return [
            'uid' => $object->uid,
            'label' => $object->label,
            'kind' => is_string($kind) ? $kind : '',
            'role' => is_string($role) ? $role : '',
            'state' => $object->state,
            'stateBadge' => match ($object->state) {
                MandateRecorder::STATE_VERIFIED => 'success',
                MandateRecorder::STATE_REJECTED => 'danger',
                default => 'info',
            },
            'updated' => $object->tstamp,
            'detailUri' => (string)$this->uriBuilder->buildUriFromRoute(ObjectKind::Mandate->inspectorModule() . '.detail', ['uid' => $object->uid]),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Webconsulting\AgentNexus\Ap2\Http\Ap2Routes;
use Webconsulting\AgentNexus\Ap2\Mandate\Money;
use Webconsulting\AgentNexus\Ap2\Sandbox\DemoCatalogue;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;

/**
 * The Trusted Surface content element.
 *
 * Renders a cacheable shell: the shopping list the agent asks the visitor to
 * approve, the spending cap and the two buttons. The script posts to
 * `POST /api/agent-nexus/ap2/authorize` and shows the chain the sandbox signs
 * and checks, step by step.
 */
final class TrustedSurfacePluginController extends ActionController
{
    public const int DEFAULT_CAP = 50000;

    public function __construct(
        private readonly DemoCatalogue $catalogue,
        private readonly RouteRegistry $routes,
    ) {}

    public function showAction(): ResponseInterface
    {
        $contentObject = $this->request->getAttribute('currentContentObject');
        $data = $contentObject instanceof ContentObjectRenderer ? $contentObject->data : [];
        $pageId = (int)($this->request->getAttribute('frontend.page.information')?->getId() ?? ($data['pid'] ?? 0));

        $shoppingList = [];
        foreach ($this->catalogue->shoppingList() as $line) {
            $shoppingList[] = [
                'title' => $line['title'],
                'quantity' => $line['quantity'],
                'options' => array_map(static fn(array $option): array => [
                    'title' => $option['title'],
                    'price' => Money::format($option['price'], Parties::CURRENCY),
                ], $line['options']),
            ];
        }

        $this->view->assignMultiple([
            'settings' => $this->settings,
            'data' => $data,
            'pageId' => $pageId,
            'endpoint' => $this->routes->get(Ap2Routes::AUTHORIZE)?->uri($this->routes->apiBasePath()) ?? '',
            'shoppingList' => $shoppingList,
            'merchant' => Parties::MERCHANT['name'],
            'defaultCap' => intdiv(self::DEFAULT_CAP, 100),
        ]);

        return $this->htmlResponse();
    }
}

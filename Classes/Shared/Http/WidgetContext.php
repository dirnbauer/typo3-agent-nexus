<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Where a frontend widget's request came from: its content element, the page
 * it sits on and the URL the visitor had open.
 *
 * The widgets talk to the same protocol endpoints any other client uses, and
 * carry this context inside the protocol's own extension point — A2A message
 * metadata, AG-UI `forwardedProps`, a top-level member of the A2UI, UCP-agent
 * and AP2 requests — always under the key {@see self::KEY}:
 *
 *     "agentNexus": {"ce": 123, "page": 45, "url": "https://…"}
 *
 * Nothing in it is trusted beyond its type: the content element is only ever
 * used to load that element's own FlexForm server side ({@see PluginSettings}),
 * and the page decides where records are stored.
 */
final class WidgetContext implements SingletonInterface
{
    public const string KEY = 'agentNexus';

    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * The context carried in a request, or null when the request did not come
     * from a widget.
     *
     * @param array<array-key, mixed> $container the object holding the "agentNexus" member
     * @return array{ce: int, page: int, url: string}|null
     */
    public function from(array $container): ?array
    {
        $context = $container[self::KEY] ?? null;
        if (!is_array($context)) {
            return null;
        }
        $ce = is_numeric($context['ce'] ?? null) ? max(0, (int)$context['ce']) : 0;
        $page = is_numeric($context['page'] ?? null) ? max(0, (int)$context['page']) : 0;
        $url = is_string($context['url'] ?? null) ? mb_substr($context['url'], 0, 2048) : '';
        if ($ce === 0 && $page === 0) {
            return null;
        }
        return ['ce' => $ce, 'page' => $page, 'url' => filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : ''];
    }

    /**
     * The page records from this widget are stored on: the storage folder of
     * the page's site (site setting `agentNexus.storagePid`) when one is set,
     * otherwise the page itself, otherwise the root (0).
     */
    public function storagePid(int $pageUid): int
    {
        if ($pageUid <= 0) {
            return 0;
        }
        try {
            $settings = $this->siteFinder->getSiteByPageId($pageUid)->getSettings();
            $storagePid = $settings->get('agentNexus.storagePid', 0);
            if (is_numeric($storagePid) && (int)$storagePid > 0) {
                return (int)$storagePid;
            }
        } catch (SiteNotFoundException) {
            // A page outside every site: store on the page itself.
        }
        return $pageUid;
    }
}

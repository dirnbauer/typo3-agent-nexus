<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\A2ui\Service\AgentService;

/**
 * Public (frontend) AJAX endpoints for the Smart Inquiry plugin.
 *
 * Registered as eIDs so they run without a page context. Both are deliberately
 * small and defensive: anonymous visitors hit these, so we cap input length and
 * rate-limit per client before ever calling the (paid) agent.
 */
final class InquiryEndpoint
{
    private const MAX_INTENT_LENGTH = 600;
    private const RATE_LIMIT = 15;          // generations …
    private const RATE_WINDOW = 600;        // … per client per 10 minutes
    private const INQUIRY_TABLE = 'tx_agentnexus_a2ui_inquiry';

    /**
     * POST intent -> tailored A2UI v1.0 surface (JSON).
     */
    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $intent = trim((string)($body['intent'] ?? ''));
        if ($intent === '') {
            return new JsonResponse(['success' => false, 'error' => 'Please describe what you need.'], 400);
        }
        if (mb_strlen($intent) > self::MAX_INTENT_LENGTH) {
            $intent = mb_substr($intent, 0, self::MAX_INTENT_LENGTH);
        }
        if (!$this->passesRateLimit($request)) {
            return new JsonResponse(['success' => false, 'error' => 'Too many requests.'], 429);
        }

        $result = GeneralUtility::makeInstance(AgentService::class)->generate($intent, [
            'source' => 'frontend',
            'businessContext' => mb_substr(trim((string)($body['businessContext'] ?? '')), 0, 2000),
            'language' => $this->resolveLanguage($request),
        ]);

        return new JsonResponse([
            'success' => true,
            'provenance' => [
                'source' => $result->getSource(),
                'model' => $result->getModel(),
                'label' => $result->getProvenanceLabel(),
            ],
            'payload' => $result->getSurface()->toMessage(),
        ]);
    }

    /**
     * POST the filled-in data model -> store an inquiry record.
     */
    public function submit(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();

        $payload = (string)($body['payload'] ?? '');
        $data = (string)($body['data'] ?? '');
        // Reject obviously oversized blobs.
        if (strlen($payload) > 200000 || strlen($data) > 50000) {
            return new JsonResponse(['success' => false, 'error' => 'Payload too large.'], 413);
        }

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::INQUIRY_TABLE)
            ->insert(self::INQUIRY_TABLE, [
                'pid' => (int)($body['page'] ?? 0),
                'crdate' => time(),
                'page_uid' => (int)($body['page'] ?? 0),
                'source_url' => mb_substr((string)($body['url'] ?? ''), 0, 2048),
                'intent' => mb_substr((string)($body['intent'] ?? ''), 0, 2000),
                'surface_id' => mb_substr((string)($body['surfaceId'] ?? ''), 0, 120),
                'payload' => $payload,
                'data' => $data,
            ]);

        return new JsonResponse(['success' => true]);
    }

    private function passesRateLimit(ServerRequestInterface $request): bool
    {
        try {
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('a2ui');
        } catch (\Throwable) {
            return true; // fail open if the cache is unavailable
        }
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $key = 'rl_' . sha1($ip);
        $count = (int)$cache->get($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        $cache->set($key, $count + 1, [], self::RATE_WINDOW);
        return true;
    }

    private function resolveLanguage(ServerRequestInterface $request): string
    {
        $language = $request->getAttribute('language');
        if ($language !== null && method_exists($language, 'getLocale')) {
            $locale = $language->getLocale();
            if (is_object($locale) && method_exists($locale, 'getLanguageCode')) {
                return (string)$locale->getLanguageCode();
            }
        }
        return 'en';
    }
}

<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Server-side FlexForm settings for the frontend eID endpoints.
 *
 * The plugins are cacheable shells whose JavaScript posts to eID endpoints,
 * so FlexForm settings do not travel with the request. Anything that guards
 * cost or shapes prompts (use_llm, system prompt, token limits) must NOT be
 * client-postable — the widgets post their content element uid instead and
 * the endpoint loads the record's pi_flexform here.
 */
final class PluginSettings implements SingletonInterface
{
    /** @var array<string, array<string, mixed>> per-request memo */
    private array $cache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FlexFormService $flexFormService,
    ) {}

    /**
     * Settings of a live tt_content plugin record, or [] when the uid does
     * not resolve to a visible element of the expected CType.
     *
     * @return array<string, mixed>
     */
    public function forContentElement(int $contentElementUid, string $expectedCType): array
    {
        if ($contentElementUid <= 0) {
            return [];
        }
        $memoKey = $contentElementUid . ':' . $expectedCType;
        if (isset($this->cache[$memoKey])) {
            return $this->cache[$memoKey];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $row = $qb
            ->select('pi_flexform', 'CType')
            ->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($contentElementUid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row) || (string)$row['CType'] !== $expectedCType) {
            return $this->cache[$memoKey] = [];
        }

        $flexForm = (string)($row['pi_flexform'] ?? '');
        if ($flexForm === '') {
            return $this->cache[$memoKey] = [];
        }

        $parsed = $this->flexFormService->convertFlexFormContentToArray($flexForm);
        $settings = is_array($parsed['settings'] ?? null) ? $parsed['settings'] : [];

        return $this->cache[$memoKey] = $settings;
    }
}

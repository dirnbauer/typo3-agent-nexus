<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\Exception\LockCreateException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * {@see TaskLock} on TYPO3's locking API, so it works with whatever locking
 * strategy the installation is configured for.
 */
#[AsAlias(TaskLock::class)]
final readonly class CoreTaskLock implements TaskLock
{
    private const int MODE = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;

    public function __construct(
        private LockFactory $lockFactory,
    ) {}

    #[\Override]
    public function acquire(string $taskId): ?\Closure
    {
        try {
            $locker = $this->lockFactory->createLocker('agentnexus_a2a_task_' . sha1($taskId), self::MODE);
            if (!$locker->acquire(self::MODE)) {
                return null;
            }
        } catch (LockAcquireWouldBlockException) {
            return null;
        } catch (LockCreateException) {
            // No strategy can lock without waiting here: go ahead unlocked
            // rather than refuse every task.
            return static function (): void {};
        }

        return static function () use ($locker): void {
            $locker->release();
        };
    }
}

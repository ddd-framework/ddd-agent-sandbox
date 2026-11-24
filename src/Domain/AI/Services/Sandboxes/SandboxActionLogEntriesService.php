<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntries;
use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntry;
use DDD\Domain\AI\Repo\DB\Sandboxes\DBSandboxActionLogEntries;
use DDD\Domain\AI\Repo\DB\Sandboxes\DBSandboxActionLogEntry;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOption;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Infrastructure\Services\DDDService;
use Throwable;

/**
 * The audit of workspace actions ({@see SandboxActionLogEntry}). A host without the table still acts: the entry
 * goes to the error log instead, and reads answer an empty list.
 *
 * @method SandboxActionLogEntry find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method SandboxActionLogEntries findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBSandboxActionLogEntry getEntityRepoClassInstance()
 * @method DBSandboxActionLogEntries getEntitySetRepoClassInstance()
 */
class SandboxActionLogEntriesService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = SandboxActionLogEntry::class;

    /** @var int The most entries one read answers */
    public const int MAX_ENTRIES_PER_READ = 200;

    /** @var int Characters kept of a reason or an outcome detail */
    public const int MAX_TEXT_LENGTH = 1000;

    /**
     * Writes one audit entry. A write that fails (no table yet) logs the entry as an error and answers null; the action
     * it describes is never undone for it.
     *
     * @param SandboxActionLogEntry $sandboxActionLogEntry
     * @return SandboxActionLogEntry|null the stored entry, null when it could only be logged
     */
    public function recordActionLogEntry(SandboxActionLogEntry $sandboxActionLogEntry): ?SandboxActionLogEntry
    {
        $sandboxActionLogEntry->reason = $this->truncateText($sandboxActionLogEntry->reason);
        $sandboxActionLogEntry->outcomeDetail = $this->truncateText($sandboxActionLogEntry->outcomeDetail);
        try {
            /** @var SandboxActionLogEntry $storedActionLogEntry */
            $storedActionLogEntry = $sandboxActionLogEntry->insert();
            return $storedActionLogEntry;
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->error('[Sandbox] audit entry not stored, logged instead', [
                'workspaceKey' => $sandboxActionLogEntry->workspaceKey,
                'agentSpaceId' => $sandboxActionLogEntry->agentSpaceId,
                'action' => $sandboxActionLogEntry->action,
                'jobId' => $sandboxActionLogEntry->jobId,
                'actorAccountId' => $sandboxActionLogEntry->actorAccountId,
                'forced' => $sandboxActionLogEntry->forced,
                'reason' => $sandboxActionLogEntry->reason,
                'outcome' => $sandboxActionLogEntry->outcome,
                'outcomeDetail' => $sandboxActionLogEntry->outcomeDetail,
                'storeError' => $throwable->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * The newest audit entries of one workspace; empty when the table is missing.
     *
     * @param string $workspaceKey
     * @param int $limit
     * @return SandboxActionLogEntries
     */
    public function findNewestActionLogEntriesOfWorkspace(string $workspaceKey, int $limit): SandboxActionLogEntries
    {
        try {
            $repoClass = $this->getEntitySetRepoClassInstance();
            $queryBuilder = $repoClass::createQueryBuilder();
            $baseModelAlias = $repoClass::getBaseModelAlias();
            $queryBuilder->andWhere("{$baseModelAlias}.workspaceKey = :workspaceKey")
                ->setParameter('workspaceKey', $workspaceKey)
                ->orderBy("{$baseModelAlias}.id", OrderByOption::DESC)
                ->setMaxResults(max(1, min($limit, static::MAX_ENTRIES_PER_READ)));
            return $repoClass->find($queryBuilder, false) ?? SandboxActionLogEntries::newInstance();
        } catch (Throwable $throwable) {
            DDDService::instance()->getLogger()->warning('[Sandbox] audit entries not readable', ['workspaceKey' => $workspaceKey, 'reason' => $throwable->getMessage()]);
            return SandboxActionLogEntries::newInstance();
        }
    }

    protected function truncateText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $trimmedText = trim($text);
        return $trimmedText === '' ? null : mb_substr($trimmedText, 0, static::MAX_TEXT_LENGTH);
    }
}

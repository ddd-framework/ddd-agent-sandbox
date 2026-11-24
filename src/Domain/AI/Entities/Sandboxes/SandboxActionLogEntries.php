<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\AI\Repo\DB\Sandboxes\DBSandboxActionLogEntries;
use DDD\Domain\AI\Services\Sandboxes\SandboxActionLogEntriesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * Audit entries of workspace actions, newest first.
 *
 * @property SandboxActionLogEntry[] $elements
 * @method SandboxActionLogEntry|null first()
 * @method SandboxActionLogEntry|null getByUniqueKey(string $uniqueKey)
 * @method SandboxActionLogEntry[] getElements()
 * @method static SandboxActionLogEntriesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBSandboxActionLogEntries::class)]
class SandboxActionLogEntries extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = SandboxActionLogEntriesService::class;
}

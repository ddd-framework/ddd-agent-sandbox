<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntries;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method SandboxActionLogEntries find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBSandboxActionLogEntries extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBSandboxActionLogEntry::class;
    public const string BASE_ENTITY_SET_CLASS = SandboxActionLogEntries::class;
}

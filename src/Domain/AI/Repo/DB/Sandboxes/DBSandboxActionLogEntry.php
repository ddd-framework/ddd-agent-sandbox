<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntry;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Services\AuthService;

/**
 * @method SandboxActionLogEntry find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method SandboxActionLogEntry update(DefaultObject &$entity, int $depth = 1)
 * @property DBSandboxActionLogEntryModel $ormInstance
 */
class DBSandboxActionLogEntry extends DBEntity
{
    public const string BASE_ENTITY_CLASS = SandboxActionLogEntry::class;
    public const string BASE_ORM_MODEL = DBSandboxActionLogEntryModel::class;

    /**
     * The audit is an operator record: only an admin with access to all resources reads it; every other caller reads
     * no row.
     */
    public static function applyReadRightsQuery(DoctrineQueryBuilder &$queryBuilder): bool
    {
        if (!self::$applyRightsRestrictions) {
            return false;
        }
        $authAccount = AuthService::instance()->getAccount();
        if ($authAccount instanceof Account && $authAccount->isAdminWithAccessToAllResources()) {
            return true;
        }
        $baseModelAlias = static::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.id is null");
        return true;
    }
}

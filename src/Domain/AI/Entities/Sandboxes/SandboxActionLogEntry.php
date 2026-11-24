<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\AI\Repo\DB\Sandboxes\DBSandboxActionLogEntry;
use DDD\Domain\AI\Services\Sandboxes\SandboxActionLogEntriesService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * The audit of one action an admin took on an agent workspace (start, offload, kill a job, end, reset): who, when,
 * on which workspace and space, whether it was forced past a running turn and why, and how it ended. Refused actions
 * are written too. The row keeps plain ids (no foreign keys), so it outlives the account and the space it names.
 * The time is the row's creation time ({@see ChangeHistoryTrait}).
 *
 * @method static SandboxActionLogEntriesService getService()
 * @method static DBSandboxActionLogEntry getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBSandboxActionLogEntry::class)]
#[DatabaseIndex(DatabaseIndex::TYPE_INDEX, ['workspaceKey', 'agentSpaceId'])]
class SandboxActionLogEntry extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string ACTION_START = 'START';
    public const string ACTION_OFFLOAD = 'OFFLOAD';
    public const string ACTION_KILL_JOB = 'KILL_JOB';
    public const string ACTION_END = 'END';
    public const string ACTION_RESET = 'RESET';
    public const array ACTIONS = [self::ACTION_START, self::ACTION_OFFLOAD, self::ACTION_KILL_JOB, self::ACTION_END, self::ACTION_RESET];

    /** @var string The action ran */
    public const string OUTCOME_SUCCEEDED = 'SUCCEEDED';

    /** @var string The action was refused before it ran (a running turn, a missing reason or confirmation) */
    public const string OUTCOME_REFUSED = 'REFUSED';

    /** @var string The administration threw */
    public const string OUTCOME_FAILED = 'FAILED';

    public const array OUTCOMES = [self::OUTCOME_SUCCEEDED, self::OUTCOME_REFUSED, self::OUTCOME_FAILED];

    /** @var string|null The workspace acted on */
    #[NotNull]
    #[Length(max: 255)]
    public ?string $workspaceKey = null;

    /** @var int|null The agent space of the workspace; null at account level */
    public ?int $agentSpaceId = null;

    /** @var string|null START, OFFLOAD, KILL_JOB, END or RESET */
    #[NotNull]
    #[Choice(choices: self::ACTIONS)]
    #[Length(max: 16)]
    public ?string $action = null;

    /** @var string|null The job a KILL_JOB named */
    #[Length(max: 128)]
    public ?string $jobId = null;

    /** @var int|null The account that acted */
    public ?int $actorAccountId = null;

    /** @var bool|null The action was forced past a running turn */
    public ?bool $forced = false;

    /** @var string|null The reason given for a forced action */
    #[Length(max: 1000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $reason = null;

    /** @var string|null SUCCEEDED, REFUSED or FAILED */
    #[NotNull]
    #[Choice(choices: self::OUTCOMES)]
    #[Length(max: 16)]
    public ?string $outcome = null;

    /** @var string|null Why it was refused or failed, or what it did */
    #[Length(max: 1000)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $outcomeDetail = null;
}

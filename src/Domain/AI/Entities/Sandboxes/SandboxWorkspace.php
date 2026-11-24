<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One sandbox workspace as the administration sees it: its session state, resource use and mount.
 * {@see \DDD\Domain\AI\Services\Sandboxes\SandboxAdministrationService} fills everything but {@see $conversationCount}
 * and {@see $hasLiveTurn}, which come from {@see \DDD\Domain\AI\Services\Sandboxes\SandboxWorkspaceActivity}. A figure the administration does not know
 * stays null.
 */
class SandboxWorkspace extends ValueObject
{
    /** @var string A runner session holds the workspace right now */
    public const string STATE_RUNNING = 'RUNNING';

    /** @var string A session exists but runs nothing */
    public const string STATE_IDLE = 'IDLE';

    /** @var string No session: the workspace lives in the store only */
    public const string STATE_OFFLOADED = 'OFFLOADED';

    public const array STATES = [self::STATE_RUNNING, self::STATE_IDLE, self::STATE_OFFLOADED];

    /** @var string The files are mounted through FUSE */
    public const string MOUNT_MODE_FUSE = 'FUSE';

    /** @var string The files are copied into a plain directory */
    public const string MOUNT_MODE_PLAIN = 'PLAIN';

    public const array MOUNT_MODES = [self::MOUNT_MODE_FUSE, self::MOUNT_MODE_PLAIN];

    /** @var string The workspace key (the agent part uses one per agent space, or per account without a space) */
    public string $workspaceKey = '';

    /** @var int|null The agent space the workspace belongs to; null at account level */
    public ?int $agentSpaceId = null;

    /** @var string|null The name of the space as the admin knows it (the same name the drive shows) */
    public ?string $displayName = null;

    /** @var string RUNNING, IDLE or OFFLOADED */
    #[Choice(choices: self::STATES)]
    public string $state = self::STATE_OFFLOADED;

    /** @var DateTime|null The last command or file access in the workspace */
    public ?DateTime $lastActivityAt = null;

    /** @var int|null Bytes the workspace uses on disk */
    public ?int $diskUsedBytes = null;

    /** @var int|null The disk quota of the workspace in bytes */
    public ?int $diskQuotaBytes = null;

    /** @var int|null The session's memory use now, in bytes */
    public ?int $memoryBytes = null;

    /** @var int|null The highest memory use of the session, in bytes */
    public ?int $memoryPeakBytes = null;

    /** @var int|null The memory limit of the session, in bytes */
    public ?int $memoryLimitBytes = null;

    /** @var string|null FUSE or PLAIN; null while no session runs */
    #[Choice(choices: self::MOUNT_MODES)]
    public ?string $mountMode = null;

    /** @var string|null Why the session runs in this mount mode (e.g. why FUSE was not available) */
    public ?string $mountModeReason = null;

    /** @var string|null The runner instance (pod) that holds the session */
    public ?string $runnerPod = null;

    /** @var string|null The name of the account that owns the space */
    public ?string $ownerDisplayName = null;

    /** @var int|null Jobs running in the background of the session */
    public ?int $runningJobCount = null;

    /** @var int|null Root conversations of the agent space (filled by the package; null at account level) */
    public ?int $conversationCount = null;

    /** @var bool A conversation of the agent space holds a live turn right now (filled by the package) */
    public bool $hasLiveTurn = false;

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->workspaceKey);
    }
}

<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\DB\Sandboxes;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'SandboxActionLogEntries')]
class DBSandboxActionLogEntryModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'SandboxActionLogEntry';

	public const string TABLE_NAME = 'SandboxActionLogEntries';

	public const string ENTITY_CLASS = 'DDD\Domain\AI\Entities\Sandboxes\SandboxActionLogEntry';

	#[ORM\Column(type: 'string')]
	public string $workspaceKey;

	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'string')]
	public string $action;

	#[ORM\Column(type: 'string')]
	public ?string $jobId;

	#[ORM\Column(type: 'integer')]
	public ?int $actorAccountId;

	#[ORM\Column(type: 'boolean')]
	public ?bool $forced = false;

	#[ORM\Column(type: 'string')]
	public ?string $reason;

	#[ORM\Column(type: 'string')]
	public string $outcome;

	#[ORM\Column(type: 'string')]
	public ?string $outcomeDetail;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}
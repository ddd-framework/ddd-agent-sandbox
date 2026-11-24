<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The expiry reports of one run, one per stored workspace.
 *
 * @implements IteratorAggregate<int, SandboxWorkspaceExpiryReport>
 */
class SandboxWorkspaceExpiryReports implements IteratorAggregate, Countable
{
    /** @var SandboxWorkspaceExpiryReport[] */
    protected array $expiryReports = [];

    public function add(SandboxWorkspaceExpiryReport $expiryReport): void
    {
        $this->expiryReports[] = $expiryReport;
    }

    /**
     * @return SandboxWorkspaceExpiryReport[]
     */
    public function getElements(): array
    {
        return $this->expiryReports;
    }

    public function findByWorkspaceKey(string $workspaceKey): ?SandboxWorkspaceExpiryReport
    {
        foreach ($this->expiryReports as $expiryReport) {
            if ($expiryReport->workspaceKey === $workspaceKey) {
                return $expiryReport;
            }
        }
        return null;
    }

    public function count(): int
    {
        return count($this->expiryReports);
    }

    /**
     * @return Traversable<int, SandboxWorkspaceExpiryReport>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->expiryReports);
    }
}

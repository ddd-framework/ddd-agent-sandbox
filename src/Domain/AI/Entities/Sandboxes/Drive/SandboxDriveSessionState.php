<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes\Drive;

/**
 * The drive side of a live session (kept in the shared cache beside the session, valid for its session id only): the
 * own drive and its working copy, the mounts, the operator, and per conversation the turn whose pull ran.
 */
class SandboxDriveSessionState
{
    public string $workspaceKey = '';

    /** @var string The runner session this state belongs to */
    public string $sessionId = '';

    /** @var int The agent space; 0 for an eval key on its scratch drive without a space */
    public int $agentSpaceId = 0;

    public int $operatorAccountId = 0;

    /** @var int The own drive: /workspace is its working copy */
    public int $driveSpaceId = 0;

    public int $driveWorkingCopyId = 0;

    public SandboxDriveMountStates $mountStates;

    /** @var string[] the turn key whose pull ran, by conversation key */
    public array $pulledTurnsByConversation = [];

    public function __construct()
    {
        $this->mountStates = new SandboxDriveMountStates();
    }

    /**
     * @param array<string, mixed> $cachedValue
     * @return static
     */
    public static function fromCachedValue(array $cachedValue): static
    {
        $sandboxDriveSessionState = new static();
        $sandboxDriveSessionState->workspaceKey = (string)($cachedValue['workspaceKey'] ?? '');
        $sandboxDriveSessionState->sessionId = (string)($cachedValue['sessionId'] ?? '');
        $sandboxDriveSessionState->agentSpaceId = (int)($cachedValue['agentSpaceId'] ?? 0);
        $sandboxDriveSessionState->operatorAccountId = (int)($cachedValue['operatorAccountId'] ?? 0);
        $sandboxDriveSessionState->driveSpaceId = (int)($cachedValue['driveSpaceId'] ?? 0);
        $sandboxDriveSessionState->driveWorkingCopyId = (int)($cachedValue['driveWorkingCopyId'] ?? 0);
        $sandboxDriveSessionState->mountStates = SandboxDriveMountStates::fromCachedValues((array)($cachedValue['mounts'] ?? []));
        $sandboxDriveSessionState->pulledTurnsByConversation = array_map('strval', (array)($cachedValue['pulledTurnsByConversation'] ?? []));
        return $sandboxDriveSessionState;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCachedValue(): array
    {
        return [
            'workspaceKey' => $this->workspaceKey,
            'sessionId' => $this->sessionId,
            'agentSpaceId' => $this->agentSpaceId,
            'operatorAccountId' => $this->operatorAccountId,
            'driveSpaceId' => $this->driveSpaceId,
            'driveWorkingCopyId' => $this->driveWorkingCopyId,
            'mounts' => $this->mountStates->toCachedValues(),
            'pulledTurnsByConversation' => $this->pulledTurnsByConversation,
        ];
    }
}

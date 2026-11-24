<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Sandboxes;

/**
 * A workspace bound to a runner pod: the session id, the pod's address (every call of the session goes there), the
 * pool it was claimed in, when it was claimed and last used, and per conversation its shell and cwd. Kept in the shared
 * cache by workspace key, so every worker and process that works on the workspace reuses it.
 *
 * Shells: a runner session has at most 4. One (`files`) runs the file tools; the conversations of the workspace share
 * the other three, each conversation sticks to one and gets the next free one when its own is busy. The cwd of a
 * conversation is kept here and passed with every command, so two conversations on one shell never see each other's
 * cwd; exported variables live in the shell and can be seen by the other conversation on it (two terminals of one
 * user).
 */
class SandboxSession
{
    /** @var string The shell of the file tools (readFile, searchFiles, the state note) */
    public const string FILES_SHELL_NAME = 'files';

    /** @var string[] The shells the conversations share */
    public const array CONVERSATION_SHELL_NAMES = ['default', 'shell2', 'shell3'];

    public string $workspaceKey = '';

    public string $sessionId = '';

    public string $runnerAddress = '';

    /** @var string The runner pool the session was claimed in ({@see SandboxRunnerPool}) */
    public string $poolName = SandboxRunnerPool::DEFAULT_POOL_NAME;

    public int $claimedAt = 0;

    public int $lastUsedAt = 0;

    /** @var string[] shell name by conversation key */
    public array $shellNamesByConversation = [];

    /** @var string[] cwd after its last command, by conversation key */
    public array $cwdsByConversation = [];

    /**
     * @param array<string, mixed> $cachedValue the value kept in the cache
     * @return static
     */
    public static function fromArray(array $cachedValue): static
    {
        $sandboxSession = new static();
        $sandboxSession->workspaceKey = (string)($cachedValue['workspaceKey'] ?? '');
        $sandboxSession->sessionId = (string)($cachedValue['sessionId'] ?? '');
        $sandboxSession->runnerAddress = (string)($cachedValue['runnerAddress'] ?? '');
        $sandboxSession->poolName = (string)($cachedValue['poolName'] ?? SandboxRunnerPool::DEFAULT_POOL_NAME);
        $sandboxSession->claimedAt = (int)($cachedValue['claimedAt'] ?? 0);
        $sandboxSession->lastUsedAt = (int)($cachedValue['lastUsedAt'] ?? 0);
        $sandboxSession->shellNamesByConversation = array_map('strval', (array)($cachedValue['shellNamesByConversation'] ?? []));
        $sandboxSession->cwdsByConversation = array_map('strval', (array)($cachedValue['cwdsByConversation'] ?? []));
        return $sandboxSession;
    }

    /**
     * @return array<string, mixed> the value kept in the cache
     */
    public function toArray(): array
    {
        return [
            'workspaceKey' => $this->workspaceKey,
            'sessionId' => $this->sessionId,
            'runnerAddress' => $this->runnerAddress,
            'poolName' => $this->poolName,
            'claimedAt' => $this->claimedAt,
            'lastUsedAt' => $this->lastUsedAt,
            'shellNamesByConversation' => $this->shellNamesByConversation,
            'cwdsByConversation' => $this->cwdsByConversation,
        ];
    }

    /**
     * The shells to try for a conversation, its own first.
     *
     * @param string $conversationKey
     * @return string[]
     */
    public function getShellNamesToTryFor(string $conversationKey): array
    {
        $ownShellName = $this->shellNamesByConversation[$conversationKey] ?? null;
        if ($ownShellName === null) {
            $ownShellName = static::CONVERSATION_SHELL_NAMES[abs(crc32($conversationKey)) % count(static::CONVERSATION_SHELL_NAMES)];
        }
        $shellNames = [$ownShellName];
        foreach (static::CONVERSATION_SHELL_NAMES as $shellName) {
            if ($shellName !== $ownShellName) {
                $shellNames[] = $shellName;
            }
        }
        return $shellNames;
    }
}

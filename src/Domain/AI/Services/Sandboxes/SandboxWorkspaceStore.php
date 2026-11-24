<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifest;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Where a workspace's files live between sessions: per workspace key a manifest (path → sha256, size, mtime, mode) and
 * the contents by sha256. The runner holds no credentials; PHP moves every byte between the runner and the store.
 *
 * The store in use is the one whose {@see getStoreName()} equals `AI.Sandbox.workspaces.store` (default `manifest`, the
 * built-in {@see SandboxManifestWorkspaceStoreService}). A host or another package adds a store by implementing this
 * interface in a service (autoconfigured by the tag).
 */
#[AutoconfigureTag(SandboxWorkspaceStore::TAG)]
interface SandboxWorkspaceStore
{
    public const string TAG = 'ai.sandbox_workspace_store';

    /** The name `AI.Sandbox.workspaces.store` selects this store by */
    public function getStoreName(): string;

    /**
     * @return SandboxWorkspaceManifest the stored manifest; an empty one for a workspace never saved
     * @throws InternalErrorException
     */
    public function readManifest(string $workspaceKey): SandboxWorkspaceManifest;

    /**
     * @throws InternalErrorException
     */
    public function writeManifest(string $workspaceKey, SandboxWorkspaceManifest $sandboxWorkspaceManifest): void;

    /**
     * @return string the content
     * @throws InternalErrorException when the content is missing (a manifest that names it is broken)
     */
    public function readBlob(string $workspaceKey, string $sha256): string;

    /**
     * Several contents at once.
     *
     * @param string $workspaceKey
     * @param string[] $sha256s
     * @return string[] content by sha256, in the order asked
     * @throws InternalErrorException
     */
    public function readBlobs(string $workspaceKey, array $sha256s): array;

    /**
     * @throws InternalErrorException
     */
    public function writeBlob(string $workspaceKey, string $sha256, string $content): void;

    /**
     * @throws InternalErrorException
     */
    public function deleteBlob(string $workspaceKey, string $sha256): void;

    /**
     * Every workspace the store holds.
     *
     * @return string[]
     * @throws InternalErrorException
     */
    public function listWorkspaceKeys(): array;

    /**
     * Removes everything the store holds for the key. A key never saved is a no-op.
     *
     * @return int the objects removed
     * @throws InternalErrorException
     */
    public function deleteWorkspace(string $workspaceKey): int;

    /**
     * Saves a workspace made of the given files, replacing what the key held: the next claim of the key restores
     * exactly these files.
     *
     * @param string $workspaceKey
     * @param string[] $contentsByPath content by path relative to /workspace
     * @return SandboxWorkspaceManifest the saved manifest
     * @throws InternalErrorException
     */
    public function createWorkspaceFromFiles(string $workspaceKey, array $contentsByPath): SandboxWorkspaceManifest;
}

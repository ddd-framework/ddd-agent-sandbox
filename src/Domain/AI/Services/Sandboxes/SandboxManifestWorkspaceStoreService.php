<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Sandboxes;

use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifest;
use DDD\Domain\AI\Entities\Sandboxes\SandboxWorkspaceManifestFile;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\ObjectStorage\ObjectStorage;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\ObjectStorageService;
use DDD\Infrastructure\Services\Service;
use DDD\Infrastructure\Untrusted\ContainedPath;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * The built-in workspace store (`AI.Sandbox.workspaces.store: manifest`): the object store
 * `AI.Sandbox.workspaces.objectStore` (default `sandbox-workspaces`), per workspace a manifest
 * (`<prefix><workspaceKey>/manifest.json`: path → sha256, size, mtime, mode) and the contents by sha256
 * (`<prefix><workspaceKey>/blobs/<sha256>`). Contents are keyed per workspace, never shared across workspaces (no
 * deduplication across owners). No database table: the manifest file is the record.
 *
 * The runner holds no credentials: PHP moves every byte between the runner and the store.
 */
class SandboxManifestWorkspaceStoreService extends Service implements SandboxWorkspaceStore
{
    /** @var string The name `AI.Sandbox.workspaces.store` selects this store by */
    public const string STORE_NAME = 'manifest';

    /** @var string The object store of the workspaces when `AI.Sandbox.workspaces.objectStore` names none */
    public const string DEFAULT_OBJECT_STORE_NAME = 'sandbox-workspaces';

    /** @var string The key prefix inside the object store when `AI.Sandbox.workspaces.keyPrefix` names none */
    public const string DEFAULT_KEY_PREFIX = 'sandbox-workspaces/';

    public function getStoreName(): string
    {
        return static::STORE_NAME;
    }

    /** @var ObjectStorage|null A store set by a test */
    protected ?ObjectStorage $objectStorageOverride = null;

    /** @var int Contents read from the store at the same time ({@see readBlobs()}) */
    public const int CONCURRENT_BLOB_READS = 8;

    /** @var int Seconds one content read of {@see readBlobs()} may take */
    public const int BLOB_READ_TIMEOUT_SECONDS = 30;

    /** @var Client|null The HTTP client of the concurrent reads (a test sets one with a mock handler) */
    protected ?Client $blobReadClient = null;

    /** @var int Seconds a content stays in the content cache (AI.Sandbox.workspaces.contentCacheSeconds) */
    public const int DEFAULT_CONTENT_CACHE_SECONDS = 259200;

    /** @var int The largest content the cache keeps (AI.Sandbox.workspaces.contentCacheMaxBytes) */
    public const int DEFAULT_CONTENT_CACHE_MAX_BYTES = 1048576;

    /** @var string|null A cache group set by a test (null = AI.Sandbox.runner.cacheGroup) */
    protected ?string $cacheGroupOverride = null;

    /**
     * @param ObjectStorage|null $objectStorage null = back to the configured store
     * @return void
     */
    public function setObjectStorage(?ObjectStorage $objectStorage): void
    {
        $this->objectStorageOverride = $objectStorage;
    }

    /**
     * @param string $workspaceKey
     * @return SandboxWorkspaceManifest the stored manifest; an empty one for a workspace never saved
     * @throws InternalErrorException
     */
    public function readManifest(string $workspaceKey): SandboxWorkspaceManifest
    {
        $content = $this->getObjectStorage()->getObjectContent($this->buildManifestKey($workspaceKey));
        if ($content === null) {
            return new SandboxWorkspaceManifest();
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            throw new InternalErrorException('The stored manifest of workspace ' . $workspaceKey . ' is no JSON object');
        }
        return SandboxWorkspaceManifest::fromArray($decoded);
    }

    /**
     * @param string $workspaceKey
     * @param SandboxWorkspaceManifest $sandboxWorkspaceManifest
     * @return void
     * @throws InternalErrorException
     */
    public function writeManifest(string $workspaceKey, SandboxWorkspaceManifest $sandboxWorkspaceManifest): void
    {
        $this->getObjectStorage()->putObject(
            $this->buildManifestKey($workspaceKey),
            json_encode($sandboxWorkspaceManifest->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'application/json'
        );
    }

    /**
     * @param string $workspaceKey
     * @param string $sha256
     * @return string the content
     * @throws InternalErrorException when the content is missing (a manifest that names it is broken)
     */
    public function readBlob(string $workspaceKey, string $sha256): string
    {
        $cachedContent = $this->getCache()->get($this->buildContentCacheKey($workspaceKey, $sha256));
        if (is_string($cachedContent) && hash('sha256', $cachedContent) === $sha256) {
            return $cachedContent;
        }
        $content = $this->getObjectStorage()->getObjectContent($this->buildBlobKey($workspaceKey, $sha256));
        if ($content === null) {
            throw new InternalErrorException('Workspace ' . $workspaceKey . ': the stored content ' . $sha256 . ' is missing');
        }
        $this->cacheContent($workspaceKey, $sha256, $content);
        return $content;
    }

    /**
     * Reads several contents at once: presigned GETs, {@see CONCURRENT_BLOB_READS} at a time. A store whose temporary
     * URLs are no HTTP URLs (the filesystem store) is read one by one. A content that fails over HTTP, or whose bytes
     * do not hash to its name, is read again through the store client (its own retries); a missing one throws.
     *
     * @param string $workspaceKey
     * @param string[] $sha256s
     * @return string[] content by sha256
     * @throws InternalErrorException
     */
    public function readBlobs(string $workspaceKey, array $sha256s): array
    {
        $sha256s = array_values(array_unique($sha256s));
        if ($sha256s === []) {
            return [];
        }
        $cachedContentsBySha256 = $this->readCachedContents($workspaceKey, $sha256s);
        $objectStorage = $this->getObjectStorage();
        $urlsBySha256 = [];
        foreach ($sha256s as $sha256) {
            if (isset($cachedContentsBySha256[$sha256])) {
                continue;
            }
            $temporaryUrl = $objectStorage->createTemporaryUrl($this->buildBlobKey($workspaceKey, $sha256), 300);
            if (!str_starts_with($temporaryUrl, 'http://') && !str_starts_with($temporaryUrl, 'https://')) {
                $urlsBySha256 = [];
                break;
            }
            $urlsBySha256[$sha256] = $temporaryUrl;
        }
        $contentsBySha256 = $urlsBySha256 !== [] ? $this->fetchConcurrently($urlsBySha256) : [];
        $orderedContents = [];
        foreach ($sha256s as $sha256) {
            if (isset($cachedContentsBySha256[$sha256])) {
                $orderedContents[$sha256] = $cachedContentsBySha256[$sha256];
                continue;
            }
            $content = $contentsBySha256[$sha256] ?? null;
            if ($content === null || hash('sha256', $content) !== $sha256) {
                $content = $this->readBlob($workspaceKey, $sha256);
            } else {
                $this->cacheContent($workspaceKey, $sha256, $content);
            }
            $orderedContents[$sha256] = $content;
        }
        return $orderedContents;
    }

    /**
     * The contents the cache holds, in one multi-get; a cached value that does not hash to its name is left out.
     *
     * @param string $workspaceKey
     * @param string[] $sha256s
     * @return string[] content by sha256
     */
    protected function readCachedContents(string $workspaceKey, array $sha256s): array
    {
        $shaByCacheKey = [];
        foreach ($sha256s as $sha256) {
            $shaByCacheKey[$this->buildContentCacheKey($workspaceKey, $sha256)] = $sha256;
        }
        $cacheKeys = array_keys($shaByCacheKey);
        $cachedValues = count($cacheKeys) === 1
            ? [$cacheKeys[0] => $this->getCache()->get($cacheKeys[0])]
            : (array)$this->getCache()->get(...$cacheKeys);
        $contentsBySha256 = [];
        foreach ($cachedValues as $cacheKey => $cachedValue) {
            $sha256 = $shaByCacheKey[$cacheKey] ?? null;
            if ($sha256 !== null && is_string($cachedValue) && hash('sha256', $cachedValue) === $sha256) {
                $contentsBySha256[$sha256] = $cachedValue;
            }
        }
        return $contentsBySha256;
    }

    /**
     * Keeps a content in the cache (contents are named by their hash, so a cached one never goes stale).
     *
     * @param string $workspaceKey
     * @param string $sha256
     * @param string $content
     * @return void
     */
    protected function cacheContent(string $workspaceKey, string $sha256, string $content): void
    {
        if (strlen($content) > (int)(Config::get('AI.Sandbox.workspaces.contentCacheMaxBytes') ?? static::DEFAULT_CONTENT_CACHE_MAX_BYTES)) {
            return;
        }
        $this->getCache()->set($this->buildContentCacheKey($workspaceKey, $sha256), $content, (int)(Config::get('AI.Sandbox.workspaces.contentCacheSeconds') ?? static::DEFAULT_CONTENT_CACHE_SECONDS));
    }

    /**
     * @param string $workspaceKey
     * @param string $sha256
     * @return string
     */
    protected function buildContentCacheKey(string $workspaceKey, string $sha256): string
    {
        return 'sandboxWorkspace.content.' . $workspaceKey . '.' . $sha256;
    }

    /**
     * @param string|null $cacheGroup null = AI.Sandbox.runner.cacheGroup
     * @return void
     */
    public function setCacheGroup(?string $cacheGroup): void
    {
        $this->cacheGroupOverride = $cacheGroup;
    }

    /**
     * @return Cache
     */
    protected function getCache(): Cache
    {
        return Cache::instance($this->cacheGroupOverride ?? (string)(Config::get('AI.Sandbox.runner.cacheGroup') ?? Cache::CACHE_GROUP_REDIS));
    }

    /**
     * @param string[] $urlsBySha256 temporary URL by sha256
     * @return string[] the body of every 200 answer by sha256; failed reads are left out
     */
    protected function fetchConcurrently(array $urlsBySha256): array
    {
        $sha256s = array_keys($urlsBySha256);
        $requests = static function () use ($urlsBySha256): \Generator {
            foreach ($urlsBySha256 as $temporaryUrl) {
                yield new Request('GET', $temporaryUrl);
            }
        };
        $contentsBySha256 = [];
        $pool = new Pool($this->getBlobReadClient(), $requests(), [
            'concurrency' => static::CONCURRENT_BLOB_READS,
            'options' => [RequestOptions::TIMEOUT => static::BLOB_READ_TIMEOUT_SECONDS, RequestOptions::CONNECT_TIMEOUT => 5, RequestOptions::HTTP_ERRORS => false],
            'fulfilled' => static function (ResponseInterface $response, int $index) use ($sha256s, &$contentsBySha256): void {
                if ($response->getStatusCode() === 200) {
                    $contentsBySha256[$sha256s[$index]] = (string)$response->getBody();
                }
            },
            // no rejected handler: a read that failed in transport is missing from the answer, and readBlobs() reads it
            // again through the store client, whose error then reaches the caller
        ]);
        $pool->promise()->wait();
        return $contentsBySha256;
    }

    /**
     * @param Client|null $client null = a plain client
     * @return void
     */
    public function setBlobReadClient(?Client $client): void
    {
        $this->blobReadClient = $client;
    }

    /**
     * @return Client
     */
    protected function getBlobReadClient(): Client
    {
        return $this->blobReadClient ??= new Client();
    }

    /**
     * Every workspace the store holds (one manifest each).
     *
     * @return string[]
     * @throws InternalErrorException
     */
    public function listWorkspaceKeys(): array
    {
        $keyPrefix = $this->getKeyPrefix();
        $workspaceKeys = [];
        foreach ($this->getObjectStorage()->listObjects($keyPrefix, PHP_INT_MAX)->getKeys() as $objectKey) {
            if (!str_ends_with($objectKey, '/manifest.json')) {
                continue;
            }
            $workspaceKey = substr($objectKey, strlen($keyPrefix), -strlen('/manifest.json'));
            if ($workspaceKey !== '' && !str_contains($workspaceKey, '/')) {
                $workspaceKeys[] = $workspaceKey;
            }
        }
        sort($workspaceKeys);
        return $workspaceKeys;
    }

    /**
     * @param string $workspaceKey
     * @param string $sha256
     * @return void
     * @throws InternalErrorException
     */
    public function deleteBlob(string $workspaceKey, string $sha256): void
    {
        $this->getObjectStorage()->deleteObject($this->buildBlobKey($workspaceKey, $sha256));
        $this->getCache()->delete($this->buildContentCacheKey($workspaceKey, $sha256));
    }

    /**
     * @param string $workspaceKey
     * @param string $sha256
     * @param string $content
     * @return void
     * @throws InternalErrorException
     */
    public function writeBlob(string $workspaceKey, string $sha256, string $content): void
    {
        $this->getObjectStorage()->putObject($this->buildBlobKey($workspaceKey, $sha256), $content, 'application/octet-stream');
        $this->cacheContent($workspaceKey, $sha256, $content);
    }

    /**
     * Saves a workspace made of the given files (path relative to /workspace → content), replacing what the key held:
     * the next claim of the key restores exactly these files. Used for the workspace of one eval case × repeat.
     *
     * @param string $workspaceKey
     * @param string[] $contentsByPath content by path relative to /workspace
     * @return SandboxWorkspaceManifest the saved manifest
     * @throws InternalErrorException
     */
    public function createWorkspaceFromFiles(string $workspaceKey, array $contentsByPath): SandboxWorkspaceManifest
    {
        $this->deleteWorkspace($workspaceKey);
        $sandboxWorkspaceManifest = new SandboxWorkspaceManifest();
        $savedAt = gmdate('Y-m-d\TH:i:s\Z');
        foreach ($contentsByPath as $path => $content) {
            $relativePath = ltrim((string)$path, '/');
            if (!ContainedPath::isSafeRelativePath($relativePath)) {
                throw new InternalErrorException('Workspace ' . $workspaceKey . ': the file path "' . $path . '" leaves the workspace');
            }
            $sha256 = hash('sha256', $content);
            if (!$sandboxWorkspaceManifest->holdsContent($sha256)) {
                $this->writeBlob($workspaceKey, $sha256, $content);
            }
            $manifestFile = new SandboxWorkspaceManifestFile();
            $manifestFile->path = $relativePath;
            $manifestFile->sha256 = $sha256;
            $manifestFile->size = strlen($content);
            $manifestFile->mtime = $savedAt;
            $sandboxWorkspaceManifest->files->add($manifestFile);
        }
        $sandboxWorkspaceManifest->version = 1;
        $sandboxWorkspaceManifest->savedAt = $savedAt;
        $this->writeManifest($workspaceKey, $sandboxWorkspaceManifest);
        return $sandboxWorkspaceManifest;
    }

    /**
     * Removes everything the store holds for the key (manifest and contents). A key never saved is a no-op.
     *
     * @param string $workspaceKey
     * @return int the objects removed
     * @throws InternalErrorException
     */
    public function deleteWorkspace(string $workspaceKey): int
    {
        $objectStorage = $this->getObjectStorage();
        $removedObjects = 0;
        $previousKeys = null;
        do {
            $keys = $objectStorage->listObjects($this->getKeyPrefix() . $workspaceKey . '/')->getKeys();
            if ($keys !== [] && $keys === $previousKeys) {
                throw new InternalErrorException('Workspace ' . $workspaceKey . ': the store lists the same objects again after deleting them');
            }
            $previousKeys = $keys;
            foreach ($keys as $objectKey) {
                $objectStorage->deleteObject($objectKey);
                $removedObjects++;
            }
        } while ($keys !== []);
        return $removedObjects;
    }

    /**
     * @param string $workspaceKey
     * @return string
     */
    public function buildManifestKey(string $workspaceKey): string
    {
        return $this->getKeyPrefix() . $workspaceKey . '/manifest.json';
    }

    /**
     * @param string $workspaceKey
     * @param string $sha256
     * @return string
     */
    public function buildBlobKey(string $workspaceKey, string $sha256): string
    {
        return $this->getKeyPrefix() . $workspaceKey . '/blobs/' . $sha256;
    }

    /**
     * @return string
     */
    protected function getKeyPrefix(): string
    {
        $keyPrefix = trim((string)(Config::get('AI.Sandbox.workspaces.keyPrefix') ?? static::DEFAULT_KEY_PREFIX), '/');
        return $keyPrefix === '' ? '' : $keyPrefix . '/';
    }

    /** The name of the object store the workspaces live in (`AI.Sandbox.workspaces.objectStore`). */
    public function getObjectStoreName(): string
    {
        return (string)(Config::get('AI.Sandbox.workspaces.objectStore') ?? static::DEFAULT_OBJECT_STORE_NAME);
    }

    /**
     * Whether the object store of the workspaces is configured (a test's store counts). An incomplete store still
     * throws: that is a configuration error, not a missing store.
     */
    public function isObjectStorageConfigured(): bool
    {
        if ($this->objectStorageOverride !== null) {
            return true;
        }
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        return $objectStorageService->getObjectStorage($this->getObjectStoreName()) !== null;
    }

    /**
     * @return ObjectStorage
     * @throws InternalErrorException when the store is not configured
     */
    protected function getObjectStorage(): ObjectStorage
    {
        if ($this->objectStorageOverride !== null) {
            return $this->objectStorageOverride;
        }
        $storeName = $this->getObjectStoreName();
        /** @var ObjectStorageService $objectStorageService */
        $objectStorageService = DDDService::instance()->getService(ObjectStorageService::class);
        $objectStorage = $objectStorageService->getObjectStorage($storeName);
        if ($objectStorage === null) {
            throw new InternalErrorException('The object store "' . $storeName . '" of the sandbox workspaces is not configured (OBJECT_STORAGE_' . strtoupper(str_replace('-', '_', $storeName)) . '_*)');
        }
        return $objectStorage;
    }
}

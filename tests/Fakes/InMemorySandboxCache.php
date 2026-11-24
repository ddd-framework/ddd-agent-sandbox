<?php

declare(strict_types=1);

namespace DDD\Tests\AgentSandbox\Fakes;

use DDD\Infrastructure\Cache\Cache;
use LogicException;
use DDD\Infrastructure\Cache\Predis\Client;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/** A process-local cache with locks for the workspace tests (values kept as they are, no expiry) */
class InMemorySandboxCache extends Cache
{
    /** @var array<string, mixed> */
    public array $valuesByKey = [];

    /** @var string[] lock token by key */
    public array $lockTokensByKey = [];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function getCacheAdapter(): Client|ApcuAdapter|RedisAdapter|PhpFilesAdapter|ChainAdapter
    {
        throw new LogicException('no adapter in the in-memory test cache');
    }

    public function get(string ...$keys): mixed
    {
        if (count($keys) === 1) {
            return $this->valuesByKey[$keys[0]] ?? null;
        }
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = $this->valuesByKey[$key] ?? null;
        }
        return $values;
    }

    public function set(string $key, mixed $value, ?int $ttl = null, bool $deferred = false): void
    {
        $this->valuesByKey[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->valuesByKey[$key]);
    }

    public function acquireLock(string $key, int $ttlSeconds): ?string
    {
        if (isset($this->lockTokensByKey[$key])) {
            return null;
        }
        return $this->lockTokensByKey[$key] = bin2hex(random_bytes(4));
    }

    public function releaseLock(string $key, string $token): bool
    {
        if (($this->lockTokensByKey[$key] ?? null) !== $token) {
            return false;
        }
        unset($this->lockTokensByKey[$key]);
        return true;
    }
}

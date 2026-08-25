<?php

namespace Dovstone\MoSQL\Cache;

use Dovstone\MoSQL\Exception\CacheException;

/**
 * Interface PSR-16 compatible
 */
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, int|null $ttl = null): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
    public function has(string $key): bool;
}

/**
 * Cache en mémoire (fallback)
 */
class ArrayCache implements CacheInterface
{
    private array $cache = [];
    private array $expires = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->has($key)) {
            return $default;
        }
        return $this->cache[$key];
    }

    public function set(string $key, mixed $value, int|null $ttl = null): bool
    {
        $this->cache[$key] = $value;
        if ($ttl !== null) {
            $this->expires[$key] = time() + $ttl;
        }
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->cache[$key], $this->expires[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->cache = [];
        $this->expires = [];
        return true;
    }

    public function has(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }
        if (isset($this->expires[$key]) && time() > $this->expires[$key]) {
            unset($this->cache[$key], $this->expires[$key]);
            return false;
        }
        return true;
    }
}

/**
 * Gestionnaire de cache avec versionnement
 */
class CacheManager
{
    private CacheInterface $cache;
    private bool $enabled;
    private int $defaultTTL;
    private string $prefix = 'MoSQL_';
    private int $version = 1;

    public function __construct(?CacheInterface $cache = null, bool $enabled = false, int $defaultTTL = 3600)
    {
        $this->cache = $cache ?? new ArrayCache();
        $this->enabled = $enabled;
        $this->defaultTTL = $defaultTTL;
        $this->version = (int)($this->cache->get($this->prefix . 'version', 1));
    }

    public function enable(): self
    {
        $this->enabled = true;
        return $this;
    }

    public function disable(): self
    {
        $this->enabled = false;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setDefaultTTL(int $ttl): self
    {
        $this->defaultTTL = $ttl;
        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->enabled) {
            return $default;
        }
        try {
            return $this->cache->get($this->prefix . $this->version . '_' . $key, $default);
        } catch (\Exception $e) {
            throw new CacheException("Cache get failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }
        try {
            return $this->cache->set(
                $this->prefix . $this->version . '_' . $key,
                $value,
                $ttl ?? $this->defaultTTL
            );
        } catch (\Exception $e) {
            throw new CacheException("Cache set failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }
        try {
            return $this->cache->delete($this->prefix . $this->version . '_' . $key);
        } catch (\Exception $e) {
            throw new CacheException("Cache delete failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function clear(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        try {
            $this->version++;
            return $this->cache->set($this->prefix . 'version', $this->version);
        } catch (\Exception $e) {
            throw new CacheException("Cache clear failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function has(string $key): bool
    {
        if (!$this->enabled) {
            return false;
        }
        try {
            return $this->cache->has($this->prefix . $this->version . '_' . $key);
        } catch (\Exception $e) {
            throw new CacheException("Cache has failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function invalidateDocument(string|int $identifier): bool
    {
        $this->delete("document_uid_{$identifier}");
        $this->delete("document_id_{$identifier}");
        return true;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCache(): CacheInterface
    {
        return $this->cache;
    }
}

<?php

namespace SilverStripe\Assets\Services;

use SilverStripe\Core\Injector\Injectable;

/**
 * Used to cache results for the duration of a request during read-only file operations
 * Do not use this during any create, update or delete operations
 */
class ReadOnlyCacheService
{

    use Injectable;

    private $enabled = false;

    private $cache = [];

    public function getEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function reset(?array $cacheNameComponents = null): void
    {
        if (is_null($cacheNameComponents)) {
            $this->cache = [];
            return;
        }
        $cacheName = $this->buildKey($cacheNameComponents);
        if (isset($this->cache[$cacheName])) {
            unset($this->cache[$cacheName]);
        }
    }

    public function setValue(array $cacheNameComponents, array $cacheKeyComponents, $value): void
    {
        $cacheName = $this->buildKey($cacheNameComponents);
        $key = $this->buildKey($cacheKeyComponents);
        if (!isset($this->cache[$cacheName])) {
            $this->cache[$cacheName] = [];
        }
        $this->cache[$cacheName][$key] = $value;
    }

    public function getValue(array $cacheNameComponents, array $cacheKeyComponents)
    {
        $cacheName = $this->buildKey($cacheNameComponents);
        $key = $this->buildKey($cacheKeyComponents);
        return $this->cache[$cacheName][$key] ?? null;
    }

    public function hasValue(array $cacheNameComponents, array $cacheKeyComponents): bool
    {
        $cacheName = $this->buildKey($cacheNameComponents);
        $key = $this->buildKey($cacheKeyComponents);
        return isset($this->cache[$cacheName]);
    }

    private function buildKey(array $components)
    {
        return implode('-', $components);
    }
}

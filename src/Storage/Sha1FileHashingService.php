<?php

namespace SilverStripe\Assets\Storage;

use InvalidArgumentException;
use League\Flysystem\Filesystem;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\Util;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Utility for computing and comparing unique file hash. All `$fs` parameters can either be:
 * * an `AssetStore` constant VISIBILITY constant or
 * * an actual `Filesystem` object.
 */
class Sha1FileHashingService implements FileHashingService, Flushable
{
    use Configurable;
    use Injectable;

    /**
     * Whetever Sha1FileHashingService should cache hash values by default.
     * @var bool
     * @config
     */
    private static $default_cachable = true;

    /**
     * Start off unset. Gets set by first call to isCached.
     * @var bool
     */
    private $cachable = null;

    /**
     * @var CacheInterface
     */
    private $cache;

    /** @var Filesystem[] */
    private $filesystems;

    public function computeFromStream($stream)
    {
        Util::rewindStream($stream);
        $hc = hash_init($this->algo() ?? '');
        hash_update_stream($hc, $stream);
        $fullHash = hash_final($hc);

        return $fullHash;
    }

    /**
     * Valid hashing algorithm constant that can be passed to `hash_init`.
     * @note A sub class could override this to create to use an alternative algorithm.
     * @return string
     */
    protected function algo()
    {
        return 'sha1';
    }

    /**
     * Get the matching Filesystem object
     * @param string|Filesystem $fs
     * @throws InvalidArgumentException
     * @return Filesystem
     */
    private function getFilesystem($fs)
    {
        if ($fs instanceof Filesystem) {
            return $fs;
        }

        if (!in_array($fs, [AssetStore::VISIBILITY_PUBLIC, AssetStore::VISIBILITY_PROTECTED])) {
            throw new InvalidArgumentException(
                'Sha1FileHashingService: $fs must be an instance of Filesystem or an AssetStore VISIBILITY constant.'
            );
        }

        if (!isset($this->filesystems[$fs])) {
            $this->filesystems[$fs] = Injector::inst()->get(sprintf('%s.%s', Filesystem::class, $fs));
        }

        return $this->filesystems[$fs];
    }

    /**
     * Get the matching key for thep provided Filesystem
     * @param string|Filesystem $keyOrFs
     * @return string
     */
    private function getFilesystemKey($keyOrFs)
    {
        if (is_string($keyOrFs)) {
            return $keyOrFs;
        }

        foreach ([AssetStore::VISIBILITY_PUBLIC, AssetStore::VISIBILITY_PROTECTED] as $visibility) {
            if ($keyOrFs === $this->getFilesystem($visibility)) {
                return $visibility;
            }
        }

        return spl_object_hash($keyOrFs);
    }

    public function computeFromFile($fileID, $fs)
    {
        if ($hash = $this->get($fileID, $fs)) {
            return $hash;
        }

        $fs = $this->getFilesystem($fs);
        $stream = $fs->readStream($fileID);
        $hash = $this->computeFromStream($stream);

        $this->set($fileID, $fs, $hash);

        return $hash;
    }

    public function compare($hashOne, $hashTwo)
    {
        // Empty hash will always return false, because they are no validatable
        if (empty($hashOne) || empty($hashTwo)) {
            throw new InvalidArgumentException('Sha1FileHashingService::validateHash can not validate empty hashes');
        }
        // Return true if $hashOne start with $hashTwo or if $hashTwo starts with $hashOne
        return strpos($hashOne ?? '', $hashTwo ?? '') === 0 || strpos($hashTwo ?? '', $hashOne ?? '') === 0;
    }

    public function isCached()
    {
        if ($this->cachable === null) {
            $this->cachable = static::config()->get('default_cachable');
        }

        return $this->cachable;
    }

    public function enableCache()
    {
        $this->cachable = true;
    }

    public function disableCache()
    {
        $this->cachable = false;
        $this->getCache()->clear();
    }

    /**
     * @return CacheInterface
     */
    private function getCache()
    {
        if (!$this->cache) {
            $this->cache = Injector::inst()->get(
                sprintf('%s.%s', CacheInterface::class, 'Sha1FileHashingService')
            );
        }

        return $this->cache;
    }

    /**
     * Build a unique cache key for the provided parameters
     * @param string $fileID
     * @param string|Filesystem $fs
     * @return string
     */
    private function buildCacheKey($fileID, $fs)
    {
        $fsID = $this->getFilesystemKey($fs);
        $cacheKey = base64_encode(sprintf('%s://%s', $fsID, $fileID));
        // base64_encode can contain `/` , but that is not a valid character in cache keys
        // @see CacheItem::validateKey. We therefore replace it with the url encoded equivalent
        // from https://tools.ietf.org/html/rfc4648#page-8 which is `_`
        return strtr($cacheKey ?? '', ['/' => '_']);
    }

    /**
     * Return the current timestamp for the provided file or the current time if the file doesn't exists.
     * @param string $fileID
     * @param string|Filesystem $fs
     * @return string
     */
    private function getTimestamp($fileID, $fs)
    {
        $filesystem = $this->getFilesystem($fs);
        return $filesystem->has($fileID) ?
            $filesystem->lastModified($fileID) :
            DBDatetime::now()->getTimestamp();
    }

    public function invalidate($fileID, $fs)
    {
        $key = $this->buildCacheKey($fileID, $fs);
        $this->getCache()->delete($key);
    }

    public static function flush()
    {
        /** @var Sha1FileHashingService $self */
        $self = Injector::inst()->get(FileHashingService::class);
        $self->getCache()->clear();
    }

    public function get($fileID, $fs)
    {
        if ($this->isCached()) {
            $key = $this->buildCacheKey($fileID, $fs);
            $value = $this->getCache()->get($key, false);
            if ($value) {
                list($timestamp, $hash) = $value;
                if ($timestamp === $this->getTimestamp($fileID, $fs)) {
                    // Only return the cached hash if the cached timestamp matches the timestamp of the physical file
                    return $hash;
                }
            }
        }
        return false;
    }


    public function set($fileID, $fs, $hash)
    {
        if ($this->isCached()) {
            $key = $this->buildCacheKey($fileID, $fs);
            // We store the file's timestamp in the cache so we can compare, that way we can know if some outside
            // process has touched the file
            $value = [
                $this->getTimestamp($fileID, $fs),
                $hash
            ];
            $this->getCache()->set($key, $value);
        }
    }

    public function move($fromFileID, $fromFs, $toFileID, $toFs = false)
    {
        $hash = $this->get($fromFileID, $fromFs);
        $this->invalidate($fromFileID, $fromFs);
        $this->set($toFileID, $toFs ?: $fromFs, $hash);
    }
}

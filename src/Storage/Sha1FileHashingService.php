<?php

namespace SilverStripe\Assets\Storage;

use InvalidArgumentException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use League\Flysystem\Util;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Utility for computing and comparing unique file hash. All `$fs` parameters can either be:
 * * an `AssetStore` constant VISIBILITY constant or
 * * an actual `Filesystem` object.
 *
 * @internal This interface is not part of the official SilverStripe API and may be altered in minor releases.
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
        $hc = hash_init($this->algo());
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
        return strpos($hashOne, $hashTwo) === 0 || strpos($hashTwo, $hashOne) === 0;
    }

    public function isCached()
    {
        if ($this->cachable === null) {
            $this->cachable = self::config()->get('default_cachable');
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

        return base64_encode(sprintf('%s://%s', $fsID, $fileID));
    }

    public function invalidate($fileID, $fs)
    {
        $key = $this->buildCacheKey($fileID, $fs);
        $this->getCache()->delete($key);
    }

    public static function flush()
    {
        /** @var self $self */
        $self = Injector::inst()->get(FileHashingService::class);
        $self->getCache()->clear();
    }

    public function get($fileID, $fs)
    {
        if ($this->isCached()) {
            $key = $this->buildCacheKey($fileID, $fs);
            return $this->getCache()->get($key, false);
        }
        return false;
    }


    public function set($fileID, $fs, $hash)
    {
        if ($this->isCached()) {
            $key = $this->buildCacheKey($fileID, $fs);
            $this->getCache()->set($key, $hash);
        }
    }

    public function move($fromFileID, $fromFs, $toFileID, $toFs = false)
    {
        $hash = $this->get($fromFileID, $fromFs);
        $this->invalidate($fromFileID, $fromFs);
        $this->set($toFileID, $toFs ?: $fromFs, $hash);
    }
}

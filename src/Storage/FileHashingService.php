<?php

namespace SilverStripe\Assets\Storage;

use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\Filesystem;

/**
 * Utility for computing and comparing unique file hash. All `$fs` parameters can either be:
 * * an `AssetStore` constant VISIBILITY constant or
 * * an actual `Filesystem` object.
 */
interface FileHashingService
{

    /**
     * Compute the Hash value of the provided stream.
     * @param resource $stream
     * @return string
     */
    public function computeFromStream($stream);

    /**
     * Compute the hash of the provided file
     * @param string $fileID
     * @param Filesystem|string $fs
     * @return string
     * @throws UnableToCheckExistence
     */
    public function computeFromFile($fileID, $fs);

    /**
     * Compare 2 full or partial hashes.
     * @param $hashOne
     * @param $hashTwo
     * @return bool
     * @throws InvalidArgumentException if one of the hash is an empty string
     */
    public function compare($hashOne, $hashTwo);

    /**
     * Whatever computed values should be cached
     * @return bool
     */
    public function isCached();

    /**
     * Enable caching of computed hash.
     * @return void
     */
    public function enableCache();

    /**
     * Disable caching of computed hash.
     * @return void
     */
    public function disableCache();

    /**
     * Invlaidate the cache for a specific key.
     * @param $fileID
     * @param $fs
     * @return void
     */
    public function invalidate($fileID, $fs);

    /**
     * Determined if we have an hash for the provided key and return the hash if present
     * @param string $fileID
     * @param Filesystem|string $fs
     * @return false|string f
     */
    public function get($fileID, $fs);

    /**
     * Explicitely set the cached hash for the provided key.
     * @param $fileID
     * @param $fs
     * @param $hash
     * @return void
     */
    public function set($fileID, $fs, $hash);

    /**
     * Move the specified hash value to a different cached key.
     * @param string $fromFileID
     * @param Filesystem|string $fromFs
     * @param string $toFileID
     * @param Filesystem|string $toFs
     * @return void
     */
    public function move($fromFileID, $fromFs, $toFileID, $toFs = false);
}

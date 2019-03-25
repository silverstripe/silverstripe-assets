<?php
namespace SilverStripe\Assets\FilenameParsing;

use League\Flysystem\Filesystem;

/**
 * Represents a strategy for resolving files on a Flysystem Adapter.
 */
interface FileResolutionStrategy
{
    /**
     * Try to resolve a file ID against the provided Filesystem.
     * @param string $fileID
     * @param Filesystem $adapter
     * @return string|null Alternative FileID where the user should be redirected to.
     */
    public function resolveFileID($fileID, Filesystem $adapter);

    /**
     * Try to find a file ID for an existing file the provided file tuple.
     * @param array|ParsedFileID $tuple
     * @param Filesystem $adapter
     * @param boolean $strict Whatever we should enforce a hash check on the file we find
     * @return string|null FileID
     */
    public function searchForTuple($tuple, Filesystem $adapter, $strict = true);

    /**
     * Build a file ID for the provided tuple, irrespective of whatever the file exists on the provided adapter or not.
     *
     * Should always return the prefered file ID for this resolution strategy.
     *
     * @param array|ParsedFileID $tuple
     * @return string
     */
    public function buildFileID($tuple);
}
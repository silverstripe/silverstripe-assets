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
     * @return string|null Alternative FileID where the user should be redirected to.
     */
    public function resolveFileID($fileID, Filesystem $adapter);

    /**
     * Try to find an file ID for an existing file the provided file tuple.
     * @param array|ParsedFileID $tuple
     * @return string|null FileID
     */
    public function searchForTuple($tuple, Filesystem $adapter);
}

<?php

namespace SilverStripe\Assets\Flysystem;

use League\Flysystem\FilesystemReader;
use League\Flysystem\StorageAttributes;

/**
 * Filesystem or adapter that can list contents using a glob.
 */
interface GlobContentLister
{
    /**
     * List the contents of a path by glob.
     *
     * For adapters, this must apply the glob as a filter at the point of fetch, i.e. it cannot filter by glob after
     * contents have been fetched. The purpose of this method is to be more efficient than filtering after fetching
     * content.
     *
     * @return iterable<StorageAttributes>
     */
    public function listContentsByGlob(string $folder, string $fileGlob, bool $deep = FilesystemReader::LIST_SHALLOW): iterable;
}

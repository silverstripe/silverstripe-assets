<?php

namespace SilverStripe\Assets\Flysystem;

use Generator;
use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem as LeagueFilesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemReader;
use League\Flysystem\PathNormalizer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToListContents;
use League\Flysystem\WhitespacePathNormalizer;
use Symfony\Component\Finder\Glob;
use Throwable;

class Filesystem extends LeagueFilesystem implements GlobContentLister
{
    private $adapter;
    private PathNormalizer $pathNormalizer;

    public function __construct(
        FilesystemAdapter $adapter,
        array $config = [],
        ?PathNormalizer $pathNormalizer = null
    ) {
        $this->adapter = $adapter;
        $this->pathNormalizer = $pathNormalizer ?: new WhitespacePathNormalizer();
        parent::__construct($adapter, $config, $pathNormalizer);
    }

    public function getAdapter(): FilesystemAdapter
    {
        return $this->adapter;
    }

    public function has(string $location): bool
    {
        $path = $this->pathNormalizer->normalizePath($location);

        return strlen($path) === 0 ? false : ($this->getAdapter()->fileExists($path) || $this->getAdapter()->directoryExists($path));
    }

    /**
     * Check if a directory is empty
     */
    public function isEmpty(string $location): bool
    {
        // listContents() uses generators, so we can start the iterator and return false if there's a single item.
        // In most cases this will be orders of magnitude faster than checking if $this->listContents($location)->toArray() is empty.
        foreach ($this->listContents($location) as $item) {
            return false;
        }
        return true;
    }

    /**
     * @return DirectoryListing<StorageAttributes>
     **/
    public function listContentsByGlob(string $folder, string $fileGlob, bool $deep = FilesystemReader::LIST_SHALLOW): DirectoryListing
    {
        $path = $this->pathNormalizer->normalizePath($folder);
        if ($this->adapter instanceof GlobContentLister) {
            $listing = $this->adapter->listContentsByGlob($path, $fileGlob, $deep);
            return new DirectoryListing($this->pipeListing($folder, $deep, $listing));
        }

        // If the adapter isn't a GlobContentLister, we have to filter by glob after fetching the content.
        // Use regex to match the found file paths against the glob pattern.
        // Regex starts with folder location
        $globRegex = '#^' . rtrim(preg_quote($path, '#'), '/') . '/';
        // Allow any number of subdirs if checking recursively
        if ($deep) {
            $globRegex .= '.*/?';
        }
        // Convert original file glob to regex and make sure any period is escaped
        // Asset file names shouldn't contain any special regex characters other than period so this should be safe.
        $globRegex .= ltrim(Glob::toRegex($fileGlob, false, true, '#'), '#^');
        $listing = $this->adapter->listContents($path, $deep);
        return new DirectoryListing($this->pipeListing(
            $folder,
            $deep,
            $listing,
            fn (StorageAttributes $item): bool => preg_match($globRegex, $item->path())
        ));
    }

    /**
     * Copied from parent::pipeListing but with the addition of an optional filter
     */
    private function pipeListing(string $location, bool $deep, iterable $listing, ?callable $filter = null): Generator
    {
        try {
            foreach ($listing as $item) {
                if ($filter === null || $filter($item)) {
                    yield $item;
                }
            }
        } catch (Throwable $exception) {
            throw UnableToListContents::atLocation($location, $deep, $exception);
        }
    }
}

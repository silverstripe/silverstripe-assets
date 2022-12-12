<?php

namespace SilverStripe\Assets\Flysystem;

use League\Flysystem\Filesystem as LeagueFilesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\PathNormalizer;
use League\Flysystem\WhitespacePathNormalizer;

class Filesystem extends LeagueFilesystem
{
    private $adapter;
    private PathNormalizer $pathNormalizer;

    public function __construct(
        FilesystemAdapter $adapter,
        array $config = [],
        PathNormalizer $pathNormalizer = null
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
}

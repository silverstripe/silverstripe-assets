<?php

namespace SilverStripe\Assets\Flysystem;

use League\Flysystem\Local\LocalFilesystemAdapter as LeagueLocalFilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\MimeTypeDetector;

class LocalFilesystemAdapter extends LeagueLocalFilesystemAdapter
{
    private PathPrefixer $pathPrefixer;

    public function __construct(
        string $location,
        VisibilityConverter $visibility = null,
        int $writeFlags = LOCK_EX,
        int $linkHandling = LocalFilesystemAdapter::DISALLOW_LINKS,
        MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->pathPrefixer = new PathPrefixer($location);

        parent::__construct($location, $visibility, $writeFlags, $linkHandling, $mimeTypeDetector, false, true);
    }

    public function prefixPath(string $path): string
    {
        return $this->pathPrefixer->prefixPath($path);
    }
}

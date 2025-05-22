<?php

namespace SilverStripe\Assets\Flysystem;

use FilesystemIterator;
use Generator;
use GlobIterator;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemReader;
use League\Flysystem\Local\LocalFilesystemAdapter as LeagueLocalFilesystemAdapter;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\SymbolicLinkEncountered;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\UnixVisibility\VisibilityConverter;
use League\MimeTypeDetection\MimeTypeDetector;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SilverStripe\Core\Path;
use SplFileInfo;
use Throwable;

class LocalFilesystemAdapter extends LeagueLocalFilesystemAdapter implements GlobContentLister
{
    private PathPrefixer $pathPrefixer;

    private int $linkHandling;

    private VisibilityConverter $visibility;

    public function __construct(
        string $location,
        ?VisibilityConverter $visibility = null,
        int $writeFlags = LOCK_EX,
        int $linkHandling = LocalFilesystemAdapter::DISALLOW_LINKS,
        ?MimeTypeDetector $mimeTypeDetector = null
    ) {
        $this->pathPrefixer = new PathPrefixer($location);
        $this->linkHandling = $linkHandling;
        $this->visibility = $visibility ??= new PortableVisibilityConverter();

        parent::__construct($location, $visibility, $writeFlags, $linkHandling, $mimeTypeDetector, false, true);
    }

    public function prefixPath(string $path): string
    {
        return $this->pathPrefixer->prefixPath($path);
    }

    /**
     * List the contents of a path by glob.
     *
     * @return iterable<StorageAttributes>
     */
    public function listContentsByGlob(string $folder, string $fileGlob, bool $deep = FilesystemReader::LIST_SHALLOW): iterable
    {
        $basePath = $this->prefixPath($folder);
        if (!is_dir($basePath)) {
            return [];
        }

        // Use the glob to match the base path first.
        yield from $this->findByGlob(Path::join($basePath, $fileGlob));

        // If we're not checking subdirectories, just return out.
        if (!$deep) {
            return;
        }

        // Check contents of subdirectories recursively.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $basePath,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_FILEINFO
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        /** @var SplFileInfo $info */
        foreach ($iterator as $path => $info) {
            if ($info->isDir()) {
                yield from $this->findByGlob(Path::join($path, $fileGlob));
            }
        }
    }

    private function findByGlob(string $glob): Generator
    {
        $iterator = new GlobIterator($glob, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $fileInfo) {
            // NOTE: The contents of this loop are directly copied from listContents()
            // except to remove `self` and to handle a private property
            $pathName = $fileInfo->getPathname();
            try {
                if ($fileInfo->isLink()) {
                    if ($this->linkHandling & LeagueLocalFilesystemAdapter::SKIP_LINKS) {
                        continue;
                    }
                    throw SymbolicLinkEncountered::atLocation($pathName);
                }

                $path = $this->pathPrefixer->stripPrefix($pathName);
                $lastModified = $fileInfo->getMTime();
                $isDirectory = $fileInfo->isDir();
                $permissions = octdec(substr(sprintf('%o', $fileInfo->getPerms()), -4));
                $visibility = $isDirectory ? $this->visibility->inverseForDirectory($permissions) : $this->visibility->inverseForFile($permissions);

                yield $isDirectory ? new DirectoryAttributes(str_replace('\\', '/', $path), $visibility, $lastModified) : new FileAttributes(
                    str_replace('\\', '/', $path),
                    $fileInfo->getSize(),
                    $visibility,
                    $lastModified
                );
            } catch (Throwable $exception) {
                if (file_exists($pathName)) {
                    throw $exception;
                }
            }
        }
    }
}

<?php

namespace SilverStripe\Assets\Tests\Flysystem\FilesystemTest;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemReader;
use SilverStripe\Assets\Flysystem\GlobContentLister;
use SilverStripe\Dev\TestOnly;
use League\Flysystem\FilesystemAdapter;

/**
 * A dummy globbably adapter that gives fixed results so we know it's being called when we expect
 */
class DummyGlobbableAdapter implements FilesystemAdapter, GlobContentLister, TestOnly
{
    public function listContentsByGlob(string $folder, string $fileGlob, bool $deep = FilesystemReader::LIST_SHALLOW): iterable
    {
        return [
            new FileAttributes('file1'),
            new FileAttributes('file2'),
            new FileAttributes('file3'),
        ];
    }

    public function fileExists(string $path): bool
    {
        return false;
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        // no-op
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        // no-op
    }

    public function read(string $path): string
    {
        return '';
    }

    public function readStream(string $path)
    {
        // no-op
    }

    public function delete(string $path): void
    {
        // no-op
    }

    public function deleteDirectory(string $path): void
    {
        // no-op
    }

    public function createDirectory(string $path, Config $config): void
    {
        // no-op
    }

    public function setVisibility(string $path, string $visibility): void
    {
        // no-op
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes('');
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes('');
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes('');
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes('');
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        // no-op
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        // no-op
    }

    protected function doFetch(array $ids): iterable
    {
        return [];
    }

    protected function doHave(string $id): bool
    {
        return false;
    }

    protected function doClear(string $namespace): bool
    {
        return false;
    }

    protected function doDelete(array $ids): bool
    {
        return false;
    }

    protected function doSave(array $values, int $lifetime): array|bool
    {
        return false;
    }
}

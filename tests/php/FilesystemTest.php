<?php

namespace SilverStripe\Assets\Tests;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Dev\SapphireTest;

class FilesystemTest extends SapphireTest
{

    /**
     * Config directory name.
     *
     * @var string
     */
    protected $directory = 'config';

    /**
     * Root directory for virtual filesystem
     *
     * @var vfsStreamDirectory
     */
    protected $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = vfsStream::setup(
            'root',
            null,
            [
                'rootfile.txt' => 'hello world',
                'empty-folder' => [],
                'not-empty-folder' => [
                  'file.txt' => 'I\'m a file'
                ],
                'folder-with-subfolders' => [
                    'empty-subfolder' => [],
                    'not-empty-subfolder' => [
                        'file-in-sub-folder.txt' => 'I am in a subfolder'
                    ],
                ],
                'folder-with-falsy-file' => [
                    '0' => 'If you parse my filename as an int, I will be false'
                ]
            ]
        );
    }

    private function buildPath(): string
    {
        $nodes = func_get_args();
        array_unshift($nodes, $this->root->url());
        return implode(DIRECTORY_SEPARATOR, $nodes);
    }

    public function testRemoveEmptyFolder()
    {
        $folderPath = $this->buildPath('empty-folder');
        $this->assertDirectoryExists($folderPath);

        Filesystem::removeFolder($folderPath);
        $this->assertDirectoryDoesNotExist($folderPath);
    }

    public function testRemoveFolderWithFiles()
    {
        $folderPath = $this->buildPath('not-empty-folder');
        $filePath = $this->buildPath('not-empty-folder', 'file.txt');
        $this->assertDirectoryExists($folderPath);
        $this->assertFileExists($filePath);

        Filesystem::removeFolder($folderPath);
        $this->assertDirectoryDoesNotExist($folderPath);
        $this->assertFileDoesNotExist($filePath);
    }

    public function testRemoveFolderWithSubFolder()
    {
        $folderPath = $this->buildPath('folder-with-subfolders');
        $emptySubfolder = $this->buildPath('folder-with-subfolders', 'empty-subfolder');
        $filePath = $this->buildPath('folder-with-subfolders', 'not-empty-subfolder', 'file-in-sub-folder.txt');

        $this->assertDirectoryExists($folderPath);
        $this->assertDirectoryExists($emptySubfolder);
        $this->assertFileExists($filePath);

        Filesystem::removeFolder($folderPath);
        $this->assertDirectoryDoesNotExist($folderPath);
        $this->assertDirectoryDoesNotExist($emptySubfolder);
        $this->assertFileDoesNotExist($filePath);
    }

    public function testRemoveFolderWithFalsyFile()
    {
        $folderPath = $this->buildPath('folder-with-falsy-file');
        $filePath = $this->buildPath('folder-with-falsy-file', '0');
        $this->assertDirectoryExists($folderPath);
        $this->assertFileExists($filePath);

        Filesystem::removeFolder($folderPath);
        $this->assertDirectoryDoesNotExist($folderPath);
        $this->assertFileDoesNotExist($filePath);
    }

    public function testRemovedFolderContentsOnly()
    {
        $folderPath = $this->buildPath('folder-with-subfolders');
        $emptySubfolder = $this->buildPath('folder-with-subfolders', 'empty-subfolder');
        $notEmptySubfolder = $this->buildPath('folder-with-subfolders', 'not-empty-subfolder');
        $filePath = $this->buildPath('folder-with-subfolders', 'not-empty-subfolder', 'file-in-sub-folder.txt');

        $this->assertDirectoryExists($folderPath);
        $this->assertDirectoryExists($emptySubfolder);
        $this->assertDirectoryExists($notEmptySubfolder);
        $this->assertFileExists($filePath);

        Filesystem::removeFolder($folderPath, true);
        $this->assertDirectoryExists($folderPath);
        $this->assertDirectoryDoesNotExist($emptySubfolder);
        $this->assertDirectoryDoesNotExist($notEmptySubfolder);
        $this->assertFileDoesNotExist($filePath);
    }
}

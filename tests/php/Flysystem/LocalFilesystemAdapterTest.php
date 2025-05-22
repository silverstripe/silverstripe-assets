<?php

namespace SilverStripe\Assets\Tests\Flysystem;

use DirectoryIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\Flysystem\LocalFilesystemAdapter;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;

class LocalFilesystemAdapterTest extends SapphireTest
{
    protected $usesDatabase = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Set up the folders and files we expect for our test.
        // We can't use the virtual filesystem provided by mikey179/vfsstream here
        // because PHP's glob can't run on virtual or remote filesystems.
        $baseDir = Path::join(__DIR__, 'LocalFilesystemAdapterTest');
        mkdir(Path::join($baseDir, 'empty-folder'));
        $folderWithContents = Path::join($baseDir, 'folder-with-contents');
        mkdir($folderWithContents);
        $subFolder = Path::join($folderWithContents, 'subfolder');
        mkdir($subFolder);
        file_put_contents(Path::join($folderWithContents, 'file.txt'), '');
        file_put_contents(Path::join($folderWithContents, 'more-file'), '');
        file_put_contents(Path::join($folderWithContents, 'special-file3.txt'), '');
        file_put_contents(Path::join($subFolder, 'subfile.txt'), '');
        file_put_contents(Path::join($subFolder, 'special-file1.blah'), '');
        file_put_contents(Path::join($subFolder, 'special-file2'), '');
        file_put_contents(Path::join($subFolder, 'another-file.txt'), '');
        $subSubFolder = Path::join($subFolder, 'subsubfolder');
        mkdir($subSubFolder);
        file_put_contents(Path::join($subSubFolder, 'special-file4.txt'), '');
    }

    public static function tearDownAfterClass(): void
    {
        $baseDir = Path::join(__DIR__, 'LocalFilesystemAdapterTest');
        $folderWithContents = Path::join($baseDir, 'folder-with-contents');
        $subFolder = Path::join($folderWithContents, 'subfolder');
        $subSubFolder = Path::join($subFolder, 'subsubfolder');
        foreach ([$subSubFolder, $subFolder, $folderWithContents, $baseDir] as $dir) {
            foreach (new DirectoryIterator($dir) as $file) {
                if ($file->isFile() && !str_ends_with($file->getPathname(), '.gitkeep')) {
                    unlink($file->getRealPath());
                }
            }
        }
        rmdir($subSubFolder);
        rmdir($subFolder);
        rmdir($folderWithContents);
        rmdir(Path::join($baseDir, 'empty-folder'));
        parent::tearDownAfterClass();
    }

    public static function provideListContentsByGlob(): array
    {
        return [
            'missing folder' => [
                'folder' => 'missing-folder',
                'fileGlob' => '*',
                'deep' => true,
                'expectedFilePaths' => [],
            ],
            'glob all empty' => [
                'folder' => 'empty-folder',
                'fileGlob' => '*',
                'deep' => true,
                'expectedFilePaths' => [],
            ],
            'glob all' => [
                'folder' => 'folder-with-contents',
                'fileGlob' => '*',
                'deep' => true,
                'expectedFilePaths' => [
                    'file.txt',
                    'subfolder',
                    'subfolder/subfile.txt',
                    'subfolder/special-file1.blah',
                    'subfolder/special-file2',
                    'subfolder/another-file.txt',
                    'subfolder/subsubfolder',
                    'subfolder/subsubfolder/special-file4.txt',
                    'more-file',
                    'special-file3.txt',
                ],
            ],
            'glob all, not deep' => [
                'folder' => 'folder-with-contents',
                'fileGlob' => '*',
                'deep' => false,
                'expectedFilePaths' => [
                    'subfolder',
                    'file.txt',
                    'more-file',
                    'special-file3.txt',
                ],
            ],
            'glob by name' => [
                'folder' => 'folder-with-contents',
                'fileGlob' => 'special-file*',
                'deep' => true,
                'expectedFilePaths' => [
                    'subfolder/special-file1.blah',
                    'subfolder/special-file2',
                    'subfolder/subsubfolder/special-file4.txt',
                    'special-file3.txt',
                ],
            ],
            'glob by name, not deep' => [
                'folder' => 'folder-with-contents',
                'fileGlob' => 'special-file*',
                'deep' => false,
                'expectedFilePaths' => [
                    'special-file3.txt',
                ],
            ],
        ];
    }

    #[DataProvider('provideListContentsByGlob')]
    public function testListContentsByGlob(string $folder, string $fileGlob, bool $deep, array $expectedFilePaths): void
    {
        $adapter = new LocalFilesystemAdapter(Path::join(__DIR__, 'LocalFilesystemAdapterTest'));
        $contents = $adapter->listContentsByGlob($folder, $fileGlob, $deep);
        $fileNames = [];
        foreach ($contents as $file) {
            $fileNames[] = str_replace($folder . '/', '', $file->path());
        }
        // Use assertEqualsCanonicalizing instead of assertSame because the order doesn't matter.
        $this->assertEqualsCanonicalizing($expectedFilePaths, $fileNames);
    }
}

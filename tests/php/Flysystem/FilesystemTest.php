<?php

namespace SilverStripe\Assets\Tests\Flysystem;

use League\Flysystem\Local\LocalFilesystemAdapter;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\Flysystem\Filesystem;
use SilverStripe\Assets\Tests\Flysystem\FilesystemTest\DummyGlobbableAdapter;
use SilverStripe\Dev\SapphireTest;

class FilesystemTest extends SapphireTest
{
    protected $usesDatabase = false;

    public static function provideIsEmpty(): array
    {
        return [
            'no folder exists' => [
                'folder' => 'doesnt-exist',
                'expected' => true,
            ],
            'folder is empty' => [
                'folder' => 'empty-folder',
                'expected' => true,
            ],
            'folder not empty' => [
                'folder' => 'not-empty-folder',
                'expected' => false,
            ],
            'folder not empty but subdir is' => [
                'folder' => 'folder-with-empty-subfolder',
                'expected' => false,
            ],
            'wierd scenario' => [
                'folder' => 'folder-with-falsy-file',
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideIsEmpty')]
    public function testIsEmpty(string $folder, bool $expected): void
    {
        $root = vfsStream::setup(
            'root',
            null,
            [
                'empty-folder' => [],
                'not-empty-folder' => [
                  'file.txt' => 'I\'m a file'
                ],
                'folder-with-empty-subfolder' => [
                    'empty-subfolder' => [],
                ],
                'folder-with-falsy-file' => [
                    '0' => 'If you parse my filename as an int, I will be false'
                ]
            ]
        );
        $filesystem = new Filesystem(new LocalFilesystemAdapter($root->url()));
        $this->assertSame($expected, $filesystem->isEmpty($folder));
    }

    public static function provideListContentsByGlob(): array
    {
        return [
            'missing folder' => [
                'adapterClass' => LocalFilesystemAdapter::class,
                'folder' => 'missing-folder',
                'fileGlob' => '*',
                'deep' => true,
                'expectedFilePaths' => [],
            ],
            'glob all empty' => [
                'adapterClass' => LocalFilesystemAdapter::class,
                'folder' => 'empty-folder',
                'fileGlob' => '*',
                'deep' => true,
                'expectedFilePaths' => [],
            ],
            'glob all' => [
                'adapterClass' => LocalFilesystemAdapter::class,
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
                    'subfolder/subsubfolder/special-file3.txt',
                    'more-file',
                    'special-file4.txt',
                ],
            ],
            'glob all, not deep' => [
                'adapterClass' => LocalFilesystemAdapter::class,
                'folder' => 'folder-with-contents',
                'fileGlob' => '*',
                'deep' => false,
                'expectedFilePaths' => [
                    'subfolder',
                    'file.txt',
                    'more-file',
                    'special-file4.txt',
                ],
            ],
            'glob by name' => [
                'adapterClass' => LocalFilesystemAdapter::class,
                'folder' => 'folder-with-contents',
                'fileGlob' => 'special-file*',
                'deep' => true,
                'expectedFilePaths' => [
                    'subfolder/special-file1.blah',
                    'subfolder/special-file2',
                    'subfolder/subsubfolder/special-file3.txt',
                    'special-file4.txt',
                ],
            ],
            'glob by name, not deep' => [
                'adapterClass' => LocalFilesystemAdapter::class,
                'folder' => 'folder-with-contents',
                'fileGlob' => 'special-file*',
                'deep' => false,
                'expectedFilePaths' => [
                    'special-file4.txt',
                ],
            ],
            'uses adapter glob when possible' => [
                'adapterClass' => DummyGlobbableAdapter::class,
                'folder' => 'folder-with-contents',
                'fileGlob' => 'special-file*',
                'deep' => false,
                'expectedFilePaths' => [
                    'file1',
                    'file2',
                    'file3',
                ],
            ],
        ];
    }

    #[DataProvider('provideListContentsByGlob')]
    public function testListContentsByGlob(string $adapterClass, string $folder, string $fileGlob, bool $deep, array $expectedFilePaths): void
    {
        $root = vfsStream::setup(
            'root',
            null,
            [
                'empty-folder' => [],
                'folder-with-contents' => [
                  'file.txt' => 'I\'m a file',
                  'subfolder' => [
                    'subfile.txt' => 'Content goes here',
                    'special-file1.blah' => 'This one is special',
                    'special-file2' => 'This one is also special',
                    'another-file.txt' => 'This one is not special',
                    'subsubfolder' => [
                        'special-file3.txt' => 'more special',
                    ],
                  ],
                  'more-file' => 'ignore this file',
                  'special-file4.txt' => 'wow how special',
                ],
            ]
        );

        $filesystem = new Filesystem(new $adapterClass($root->url()));
        $contents = $filesystem->listContentsByGlob($folder, $fileGlob, $deep);
        $fileNames = [];
        foreach ($contents as $file) {
            $fileNames[] = str_replace($folder . '/', '', $file->path());
        }
        // Use assertEqualsCanonicalizing instead of assertSame because the order doesn't matter.
        $this->assertEqualsCanonicalizing($expectedFilePaths, $fileNames);
    }
}

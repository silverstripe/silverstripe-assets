<?php

namespace SilverStripe\Assets\Tests\Storage;

use Exception;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use PHPUnit\Framework\Attributes\DataProvider;

class AssetStoreTest extends SapphireTest
{

    protected function setUp(): void
    {
        parent::setUp();

        // Set backend and base url
        TestAssetStore::activate('AssetStoreTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * @return TestAssetStore
     */
    protected function getBackend()
    {
        return Injector::inst()->get(AssetStore::class);
    }

    /**
     * Test different storage methods
     */
    public function testStorageMethods()
    {
        $backend = $this->getBackend();

        // Test setFromContent
        $puppies1 = 'puppies';
        $puppies1Tuple = $backend->setFromString($puppies1, 'pets/my-puppy.txt');
        $this->assertEquals(
            [
                'Hash' => '2a17a9cb4be918774e73ba83bd1c1e7d000fdd53',
                'Filename' => 'pets/my-puppy.txt',
                'Variant' => '',
            ],
            $puppies1Tuple
        );

        // Test setFromStream (seekable)
        $fish1 = realpath(__DIR__ . '/../ImageTest/test-image-high-quality.jpg');
        $fish1Stream = fopen($fish1 ?? '', 'r');
        $fish1Tuple = $backend->setFromStream($fish1Stream, 'parent/awesome-fish.jpg');
        fclose($fish1Stream);
        $this->assertEquals(
            [
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'parent/awesome-fish.jpg',
                'Variant' => '',
            ],
            $fish1Tuple
        );

        // Test with non-seekable streams
        TestAssetStore::$seekable_override = false;
        $fish2 = realpath(__DIR__ . '/../ImageTest/test-image-low-quality.jpg');
        $fish2Stream = fopen($fish2 ?? '', 'r');
        $fish2Tuple = $backend->setFromStream($fish2Stream, 'parent/mediocre-fish.jpg');
        fclose($fish2Stream);

        $this->assertEquals(
            [
                'Hash' => '33be1b95cba0358fe54e8b13532162d52f97421c',
                'Filename' => 'parent/mediocre-fish.jpg',
                'Variant' => '',
            ],
            $fish2Tuple
        );
        TestAssetStore::$seekable_override = null;
    }

    /**
     * Test that the backend correctly resolves conflicts
     */
    public function testConflictResolution()
    {
        $backend = $this->getBackend();

        // Put a file in
        $fish1 = realpath(__DIR__ . '/../ImageTest/test-image-high-quality.jpg');
        $this->assertFileExists($fish1);
        $fish1Tuple = $backend->setFromLocalFile($fish1, 'directory/lovely-fish.jpg');
        $this->assertEquals(
            [
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ],
            $fish1Tuple
        );

        $this->assertEquals(
            '/assets/directory/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fish1Tuple['Filename'], $fish1Tuple['Hash']),
            'Files should default to being written to the protected store'
        );

        // Write a different file with same name. Should not detect duplicates since sha are different
        $fish2 = realpath(__DIR__ . '/../ImageTest/test-image-low-quality.jpg');
        $fish2Tuple = $backend->setFromLocalFile(
            $fish2,
            'directory/lovely-fish.jpg',
            '',
            null,
            ['conflict' => AssetStore::CONFLICT_EXCEPTION]
        );

        $this->assertEquals(
            [
                'Hash' => '33be1b95cba0358fe54e8b13532162d52f97421c',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ],
            $fish2Tuple
        );
        $this->assertEquals(
            '/assets/directory/33be1b95cb/lovely-fish.jpg',
            $backend->getAsURL($fish2Tuple['Filename'], $fish2Tuple['Hash'])
        );

        // Write original file back with rename
        $this->assertFileExists($fish1);
        $fish3Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish.jpg',
            null,
            null,
            ['conflict' => AssetStore::CONFLICT_RENAME]
        );
        $this->assertEquals(
            [
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish-v2.jpg',
                'Variant' => '',
            ],
            $fish3Tuple
        );
        $this->assertEquals(
            '/assets/directory/a870de278b/lovely-fish-v2.jpg',
            $backend->getAsURL($fish3Tuple['Filename'], $fish3Tuple['Hash'])
        );

        // Write another file should increment to -v3
        $fish4Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish.jpg',
            null,
            null,
            ['conflict' => AssetStore::CONFLICT_RENAME]
        );
        $this->assertEquals(
            [
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish-v3.jpg',
                'Variant' => '',
            ],
            $fish4Tuple
        );
        $this->assertEquals(
            '/assets/directory/a870de278b/lovely-fish-v3.jpg',
            $backend->getAsURL($fish4Tuple['Filename'], $fish4Tuple['Hash'])
        );

        // Test conflict use existing file
        $fish5Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish.jpg',
            null,
            null,
            ['conflict' => AssetStore::CONFLICT_USE_EXISTING]
        );
        $this->assertEquals(
            [
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ],
            $fish5Tuple
        );
        $this->assertEquals(
            '/assets/directory/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fish5Tuple['Filename'], $fish5Tuple['Hash'])
        );

        // Test conflict use existing file
        $fish6Tuple = $backend->setFromLocalFile(
            $fish1,
            'directory/lovely-fish.jpg',
            null,
            null,
            ['conflict' => AssetStore::CONFLICT_OVERWRITE]
        );
        $this->assertEquals(
            [
                'Hash' => 'a870de278b475cb75f5d9f451439b2d378e13af1',
                'Filename' => 'directory/lovely-fish.jpg',
                'Variant' => '',
            ],
            $fish6Tuple
        );
        $this->assertEquals(
            '/assets/directory/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fish6Tuple['Filename'], $fish6Tuple['Hash'])
        );
    }

    /**
     * Data provider for reversible file ids
     *
     * @return array
     */
    public static function dataProviderFileIDs()
    {
        return [
            [
                'directory/2a17a9cb4b/file.jpg',
                [
                    'Filename' => 'directory/file.jpg',
                    'Hash' => substr(sha1('puppies'), 0, 10),
                    'Variant' => null
                ],
            ],
            [
                '2a17a9cb4b/file.jpg',
                [
                    'Filename' => 'file.jpg',
                    'Hash' => substr(sha1('puppies'), 0, 10),
                    'Variant' => null
                ],
            ],
            [
                'dir_ectory/2a17a9cb4b/file_e.jpg',
                [
                    'Filename' => 'dir_ectory/file_e.jpg',
                    'Hash' => substr(sha1('puppies'), 0, 10),
                    'Variant' => null
                ],
            ],
            [
                'directory/2a17a9cb4b/file__variant.jpg',
                [
                    'Filename' => 'directory/file.jpg',
                    'Hash' => substr(sha1('puppies'), 0, 10),
                    'Variant' => 'variant'
                ],
            ],
            [
                '2a17a9cb4b/file__var__iant.jpg',
                [
                    'Filename' => 'file.jpg',
                    'Hash' => substr(sha1('puppies'), 0, 10),
                    'Variant' => 'var__iant'
                ],
            ],
            [
                '2a17a9cb4b/file__var__iant',
                [
                    'Filename' => 'file',
                    'Hash' => substr(sha1('puppies'), 0, 10),
                    'Variant' => 'var__iant'
                ],
            ],
            [
                '2a17a9cb4b/file',
                [
                    'Filename' => 'file',
                    'Hash' => substr(sha1('puppies'), 0, 10),
                    'Variant' => null
                ],
            ]
        ];
    }

    /**
     * Data providers for files which need cleaning up (only when generating fileID)
     */
    public static function dataProviderDirtyFileIDs()
    {
        return [
            [
                'directory/2a17a9cb4b/file_variant.jpg',
                [
                    'Filename' => 'directory/file__variant.jpg', // '__' in filename is invalid, will get collapsed
                    'Hash' => sha1('puppies'),
                    'Variant' => null
                ],
            ]
        ];
    }

    /**
     * Test internal file Id generation
     *
     * @param string $fileID Expected file ID
     * @param array $tuple Tuple that generates this file ID
     */
    #[DataProvider('dataProviderFileIDs')]
    #[DataProvider('dataProviderDirtyFileIDs')]
    public function testGetFileID($fileID, $tuple)
    {
        /** @var TestAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $this->assertEquals(
            $fileID,
            $store->getFileID($tuple['Filename'], $tuple['Hash'], $tuple['Variant'])
        );
    }

    public function testGetMetadata()
    {
        $backend = $this->getBackend();

        // jpg
        $fish = realpath(__DIR__ . '/../ImageTest/test-image-high-quality.jpg');
        $fishTuple = $backend->setFromLocalFile($fish, 'parent/awesome-fish.jpg');
        $this->assertEquals(
            'image/jpeg',
            $backend->getMimeType($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $fishMeta = $backend->getMetadata($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertEquals(151889, $fishMeta['size']);
        $this->assertEquals('image/jpeg', $fishMeta['type']);
        $this->assertNotEmpty($fishMeta['timestamp']);

        // text
        $puppies = 'puppies';
        $puppiesTuple = $backend->setFromString($puppies, 'pets/my-puppy.txt');
        $this->assertEquals(
            'text/plain',
            $backend->getMimeType($puppiesTuple['Filename'], $puppiesTuple['Hash'])
        );
        $puppiesMeta = $backend->getMetadata($puppiesTuple['Filename'], $puppiesTuple['Hash']);
        $this->assertEquals(7, $puppiesMeta['size']);
        $this->assertEquals('text/plain', $puppiesMeta['type']);
        $this->assertNotEmpty($puppiesMeta['timestamp']);
    }

    /**
     * Test default conflict resolution
     */
    public function testDefaultConflictResolution()
    {
        $store = $this->getBackend();

        $this->assertEquals(AssetStore::CONFLICT_OVERWRITE, $store->getDefaultConflictResolution(null));
        $this->assertEquals(AssetStore::CONFLICT_OVERWRITE, $store->getDefaultConflictResolution('somevariant'));
    }

    /**
     * Test protect / publish mechanisms
     */
    public function testProtect()
    {
        $backend = $this->getBackend();
        $fish = realpath(__DIR__ . '/../ImageTest/test-image-high-quality.jpg');
        $fishTuple = $backend->setFromLocalFile(
            $fish,
            'parent/lovely-fish.jpg',
            null,
            null,
            ['visibility' => AssetStore::VISIBILITY_PUBLIC]
        );
        $fishVariantTuple = $backend->setFromLocalFile($fish, $fishTuple['Filename'], $fishTuple['Hash'], 'copy');

        // Test public file storage
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/parent/lovely-fish.jpg');
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/parent/lovely-fish__copy.jpg');
        $this->assertEquals(
            AssetStore::VISIBILITY_PUBLIC,
            $backend->getVisibility($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/parent/lovely-fish.jpg',
            $backend->getAsURL($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $this->assertEquals(
            '/assets/AssetStoreTest/parent/lovely-fish__copy.jpg',
            $backend->getAsURL($fishVariantTuple['Filename'], $fishVariantTuple['Hash'], $fishVariantTuple['Variant'])
        );

        // Test access rights to public files cannot be revoked
        $backend->revoke($fishTuple['Filename'], $fishTuple['Hash']); // can't revoke public assets
        $this->assertTrue($backend->canView($fishTuple['Filename'], $fishTuple['Hash']));

        // Test protected file storage
        $backend->protect($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertFileDoesNotExist(ASSETS_PATH . '/AssetStoreTest/parent/lovely-fish.jpg');
        $this->assertFileDoesNotExist(ASSETS_PATH . '/AssetStoreTest/parent/lovely-fish__copy.jpg');
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/.protected/parent/a870de278b/lovely-fish.jpg');
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/.protected/parent/a870de278b/lovely-fish__copy.jpg');
        $this->assertEquals(
            AssetStore::VISIBILITY_PROTECTED,
            $backend->getVisibility($fishTuple['Filename'], $fishTuple['Hash'])
        );

        // Test access rights
        $backend->revoke($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertFalse($backend->canView($fishTuple['Filename'], $fishTuple['Hash']));
        $backend->grant($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertTrue($backend->canView($fishTuple['Filename'], $fishTuple['Hash']));

        // Protected urls should go through asset routing mechanism
        $this->assertEquals(
            '/assets/parent/a870de278b/lovely-fish.jpg',
            $backend->getAsURL($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $this->assertEquals(
            '/assets/parent/a870de278b/lovely-fish__copy.jpg',
            $backend->getAsURL($fishVariantTuple['Filename'], $fishVariantTuple['Hash'], $fishVariantTuple['Variant'])
        );

        // Publish reverts visibility
        $backend->publish($fishTuple['Filename'], $fishTuple['Hash']);
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/parent/lovely-fish.jpg');
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/parent/lovely-fish__copy.jpg');
        $this->assertFileDoesNotExist(ASSETS_PATH . '/AssetStoreTest/.protected/parent/a870de278b/lovely-fish.jpg');
        $this->assertFileDoesNotExist(ASSETS_PATH . '/AssetStoreTest/.protected/parent/a870de278b/lovely-fish__copy.jpg');
        $this->assertEquals(
            AssetStore::VISIBILITY_PUBLIC,
            $backend->getVisibility($fishTuple['Filename'], $fishTuple['Hash'])
        );

        // Protected urls should go through asset routing mechanism
        $this->assertEquals(
            '/' . ASSETS_DIR . '/AssetStoreTest/parent/lovely-fish.jpg',
            $backend->getAsURL($fishTuple['Filename'], $fishTuple['Hash'])
        );
        $this->assertEquals(
            '/' . ASSETS_DIR . '/AssetStoreTest/parent/lovely-fish__copy.jpg',
            $backend->getAsURL($fishVariantTuple['Filename'], $fishVariantTuple['Hash'], $fishVariantTuple['Variant'])
        );
    }

    public function testRename()
    {
        $backend = $this->getBackend();
        $fish1 = realpath(__DIR__ . '/../ImageTest/test-image-high-quality.jpg');

        // Create file with various variants
        $fish1Tuple = $backend->setFromLocalFile($fish1, 'directory/lovely-fish.jpg');
        $backend->setFromLocalFile($fish1, $fish1Tuple['Filename'], $fish1Tuple['Hash'], 'somevariant');
        $backend->setFromLocalFile($fish1, $fish1Tuple['Filename'], $fish1Tuple['Hash'], 'anothervariant');

        // Move to new filename
        $newFilename = 'another-file.jpg';
        $confirmedFilename = $backend->rename($fish1Tuple['Filename'], $fish1Tuple['Hash'], $newFilename);

        // Check result
        $this->assertEquals($newFilename, $confirmedFilename);
        $this->assertNotEquals($fish1Tuple['Filename'], $confirmedFilename);

        // Check old files no longer exist
        $this->assertFalse($backend->exists($fish1Tuple['Filename'], $fish1Tuple['Hash']));
        $this->assertFalse($backend->exists($fish1Tuple['Filename'], $fish1Tuple['Hash'], 'somevariant'));
        $this->assertFalse($backend->exists($fish1Tuple['Filename'], $fish1Tuple['Hash'], 'anothervariant'));

        // New files exist
        $this->assertTrue($backend->exists($newFilename, $fish1Tuple['Hash']));
        $this->assertTrue($backend->exists($newFilename, $fish1Tuple['Hash'], 'somevariant'));
        $this->assertTrue($backend->exists($newFilename, $fish1Tuple['Hash'], 'anothervariant'));

        // Ensure we aren't getting false positives for exists()
        $this->assertFalse($backend->exists($fish1Tuple['Filename'], $fish1Tuple['Hash'], 'nonvariant'));
        $this->assertFalse($backend->exists($fish1Tuple['Filename'], sha1('nothash')));
        $this->assertFalse($backend->exists($newFilename, $fish1Tuple['Hash'], 'nonvariant'));
        $this->assertFalse($backend->exists('notfilename.jpg', $fish1Tuple['Hash']));
    }

    public function testCopy()
    {
        $backend = $this->getBackend();
        $fish1 = realpath(__DIR__ . '/../ImageTest/test-image-high-quality.jpg');

        // Create file with various variants
        $fish1Tuple = $backend->setFromLocalFile($fish1, 'directory/lovely-fish.jpg');
        $backend->setFromLocalFile($fish1, $fish1Tuple['Filename'], $fish1Tuple['Hash'], 'somevariant');
        $backend->setFromLocalFile($fish1, $fish1Tuple['Filename'], $fish1Tuple['Hash'], 'anothervariant');

        // Move to new filename
        $newFilename = 'another-file.jpg';
        $confirmedFilename = $backend->copy($fish1Tuple['Filename'], $fish1Tuple['Hash'], $newFilename);

        // Check result
        $this->assertEquals($newFilename, $confirmedFilename);
        $this->assertNotEquals($fish1Tuple['Filename'], $confirmedFilename);

        // Check old files haven't been deleted
        $this->assertTrue($backend->exists($fish1Tuple['Filename'], $fish1Tuple['Hash']));
        $this->assertTrue($backend->exists($fish1Tuple['Filename'], $fish1Tuple['Hash'], 'somevariant'));
        $this->assertTrue($backend->exists($fish1Tuple['Filename'], $fish1Tuple['Hash'], 'anothervariant'));

        // New files exist
        $this->assertTrue($backend->exists($newFilename, $fish1Tuple['Hash']));
        $this->assertTrue($backend->exists($newFilename, $fish1Tuple['Hash'], 'somevariant'));
        $this->assertTrue($backend->exists($newFilename, $fish1Tuple['Hash'], 'anothervariant'));
    }

    public function testStoreLocationWritingLogic()
    {
        $backend = $this->getBackend();

        // Test defaults
        $tuple = $backend->setFromString('defaultsToProtectedStore', 'defaultsToProtectedStore.txt');
        $this->assertEquals(
            AssetStore::VISIBILITY_PROTECTED,
            $backend->getVisibility($tuple['Filename'], $tuple['Hash'])
        );

        // Test protected
        $tuple = $backend->setFromString(
            'explicitely Protected Store',
            'explicitelyProtectedStore.txt',
            null,
            null,
            ['visibility' => AssetStore::VISIBILITY_PROTECTED]
        );
        $this->assertEquals(
            AssetStore::VISIBILITY_PROTECTED,
            $backend->getVisibility($tuple['Filename'], $tuple['Hash'])
        );

        $tuple = $backend->setFromString(
            'variant Protected Store',
            'explicitelyProtectedStore.txt',
            $tuple['Hash'],
            'variant'
        );
        $hash = substr($tuple['Hash'] ?? '', 0, 10);
        $this->assertFileExists(
            ASSETS_PATH .
            "/AssetStoreTest/.protected/$hash/explicitelyProtectedStore__variant.txt"
        );

        // Test public
        $tuple = $backend->setFromString(
            'explicitely public Store',
            'explicitelyPublicStore.txt',
            null,
            null,
            ['visibility' => AssetStore::VISIBILITY_PUBLIC]
        );
        $this->assertEquals(
            AssetStore::VISIBILITY_PUBLIC,
            $backend->getVisibility($tuple['Filename'], $tuple['Hash'])
        );

        $tuple = $backend->setFromString(
            'variant public Store',
            'explicitelyPublicStore.txt',
            $tuple['Hash'],
            'variant'
        );
        $this->assertFileExists(ASSETS_PATH . '/AssetStoreTest/explicitelyPublicStore__variant.txt');
    }

    public static function listOfVariantsToWrite()
    {
        $content = "The quick brown fox jumps over the lazy dog.";
        $hash = sha1($content ?? '');
        $filename = 'folder/file.txt';
        $variant = 'uppercase';
        $parsedFiledID = new ParsedFileID($filename, $hash);
        $variantParsedFiledID = $parsedFiledID->setVariant($variant);

        $hashHelper = new HashFileIDHelper();
        $hashPath = $hashHelper->buildFileID($parsedFiledID);
        $variantHashPath = $hashHelper->buildFileID($variantParsedFiledID);
        $naturalHelper = new NaturalFileIDHelper();
        $naturalPath = $naturalHelper->buildFileID($parsedFiledID);
        $variantNaturalPath = $naturalHelper->buildFileID($variantParsedFiledID);

        return [
            ['Public', $hashPath, $content, $variantParsedFiledID, $variantHashPath],
            ['Public', $variantNaturalPath, $content, $variantParsedFiledID, $variantNaturalPath],
            ['Protected', $hashPath, $content, $variantParsedFiledID, $variantHashPath],
            ['Protected', $variantNaturalPath, $content, $variantParsedFiledID, $variantNaturalPath],
        ];
    }

    /**
     * Make sure that variants are written next to their parent file
     */
    #[DataProvider('listOfVariantsToWrite')]
    public function testVariantWriteNextToFile(
        $fsName,
        $mainFilePath,
        $content,
        ParsedFileID $variantParsedFileID,
        $expectedVariantPath
    ) {
        $fsMethod = "get{$fsName}Filesystem";

        /** @var Filesystem $fs */
        $fs = $this->getBackend()->$fsMethod();
        $fs->write($mainFilePath, $content);
        $this->getBackend()->setFromString(
            'variant content',
            $variantParsedFileID->getFilename(),
            $variantParsedFileID->getHash(),
            $variantParsedFileID->getVariant()
        );

        $this->assertTrue($fs->fileExists($expectedVariantPath));
    }

    public static function listOfFilesToNormalise()
    {
        $public = AssetStore::VISIBILITY_PUBLIC;
        $protected = AssetStore::VISIBILITY_PROTECTED;

        /** @var FileIDHelper $hashHelper */
        $hashHelper = new HashFileIDHelper();
        $naturalHelper = new NaturalFileIDHelper();

        $content = "The quick brown fox jumps over the lazy dog.";
        $hash = sha1($content ?? '');
        $filename = 'folder/file.txt';
        $hashPath = $hashHelper->buildFileID($filename, $hash);

        $variant = 'uppercase';
        $vContent = strtoupper($content ?? '');
        $vNatural = $naturalHelper->buildFileID($filename, $hash, $variant);
        $vHash = $hashHelper->buildFileID($filename, $hash, $variant);

        return [
            // Main file only
            [$public, [$filename => $content], $filename, $hash, [$filename], [$hashPath, dirname($hashPath ?? '')]],
            [$public, [$hashPath => $content], $filename, $hash, [$filename], [$hashPath, dirname($hashPath ?? '')]],
            [$protected, [$filename => $content], $filename, $hash, [$hashPath], [$filename]],
            [$protected, [$hashPath => $content], $filename, $hash, [$hashPath], [$filename]],

            // Main File with variant
            [
                $public,
                [$filename => $content, $vNatural => $vContent],
                $filename,
                $hash,
                [$filename, $vNatural],
                [$hashPath, $vHash, dirname($hashPath ?? '')]
            ],
            [
                $public,
                [$hashPath => $content, $vHash => $vContent],
                $filename,
                $hash,
                [$filename, $vNatural],
                [$hashPath, $vHash, dirname($hashPath ?? '')]
            ],
            [
                $protected,
                [$filename => $content, $vNatural => $vContent],
                $filename,
                $hash,
                [$hashPath, $vHash],
                [$filename, $vNatural]
            ],
            [
                $protected,
                [$hashPath => $content, $vHash => $vContent],
                $filename,
                $hash,
                [$hashPath, $vHash],
                [$filename, $vNatural]
            ],
        ];
    }

    /**
     * @param string $fsName
     * @param array $contents
     * @param string $filename
     * @param string $hash
     * @param array $expected
     * @param array $notExpected
     */
    #[DataProvider('listOfFilesToNormalise')]
    public function testNormalise($fsName, array $contents, $filename, $hash, array $expected, array $notExpected = [])
    {
        /** @var FileIDHelperResolutionStrategy $protectedStrat */
        $protectedStrat = Injector::inst()->get(FileResolutionStrategy::class . '.protected');
        $originalHelpers = $protectedStrat->getResolutionFileIDHelpers();
        $protectedStrat->setResolutionFileIDHelpers(array_merge($originalHelpers, [new NaturalFileIDHelper()]));

        $this->writeDummyFiles($fsName, $contents);

        $results = $this->getBackend()->normalise($filename, $hash);

        $this->assertEquals($filename, $results['Filename']);
        $this->assertEquals($hash, $results['Hash']);

        $fs = $this->getFilesystem($fsName);

        foreach ($expected as $expectedFile) {
            $this->assertTrue($fs->has($expectedFile), "$expectedFile should exists");
            $this->assertNotEmpty($fs->read($expectedFile), "$expectedFile should be non empty");
        }

        foreach ($notExpected as $notExpectedFile) {
            $this->assertFalse($fs->has($notExpectedFile), "$notExpectedFile should NOT exists");
        }

        $protectedStrat->setResolutionFileIDHelpers($originalHelpers);
    }

    public static function listOfFileIDsToNormalise()
    {
        $public = AssetStore::VISIBILITY_PUBLIC;
        $protected = AssetStore::VISIBILITY_PROTECTED;

        /** @var FileIDHelper $hashHelper */
        $hashHelper = new HashFileIDHelper();
        $naturalHelper = new NaturalFileIDHelper();

        $content = "The quick brown fox jumps over the lazy dog.";
        $hash = sha1($content ?? '');
        $filename = 'folder/file.txt';
        $hashPath = $hashHelper->buildFileID($filename, $hash);

        $variant = 'uppercase';
        $vContent = strtoupper($content ?? '');
        $vNatural = $naturalHelper->buildFileID($filename, $hash, $variant);
        $vHash = $hashHelper->buildFileID($filename, $hash, $variant);

        return [
            // Main file only
            [$public, [$filename => $content], $filename, [$filename], [$hashPath, dirname($hashPath ?? '')]],
            [$public, [$hashPath => $content], $hashPath, [$filename], [$hashPath, dirname($hashPath ?? '')]],
            [$protected, [$filename => $content], $filename, [$hashPath], [$filename]],
            [$protected, [$hashPath => $content], $hashPath, [$hashPath], [$filename]],

            // Main File with variant
            [
                $public,
                [$filename => $content, $vNatural => $vContent],
                $filename,
                [$filename, $vNatural],
                [$hashPath, $vHash, dirname($hashPath ?? '')]
            ],
            [
                $public,
                [$hashPath => $content, $vHash => $vContent],
                $hashPath,
                [$filename, $vNatural],
                [$hashPath, $vHash, dirname($hashPath ?? '')]
            ],
            [
                $protected,
                [$filename => $content, $vNatural => $vContent],
                $filename,
                [$hashPath, $vHash],
                [$filename, $vNatural]
            ],
            [
                $protected,
                [$hashPath => $content, $vHash => $vContent],
                $hashPath,
                [$hashPath, $vHash],
                [$filename, $vNatural]
            ],

            // Test files with a parent folder that could be confused for an hash folder
            'natural path in public store with 10-char folder' => [
                $public,
                ['multimedia/video.mp4' => $content],
                'multimedia/video.mp4',
                ['multimedia/video.mp4'],
                [],
                'multimedia/video.mp4'
            ],
            'natural path in protected store with 10-char folder' => [
                $protected,
                ['multimedia/video.mp4' => $content],
                'multimedia/video.mp4',
                [$hashHelper->buildFileID('multimedia/video.mp4', $hash)],
                [],
                'multimedia/video.mp4'
            ],
            'natural path in public store with 10-hexadecimal-char folder' => [
                $public,
                ['0123456789/video.mp4' => $content],
                '0123456789/video.mp4',
                ['0123456789/video.mp4'],
                [],
                '0123456789/video.mp4'
            ],
            'natural path in protected store with 10-hexadecimal-char folder' => [
                $protected,
                ['abcdef7890/video.mp4' => $content],
                'abcdef7890/video.mp4',
                [$hashHelper->buildFileID('abcdef7890/video.mp4', $hash)],
                [],
                'abcdef7890/video.mp4'
            ],
        ];
    }

    /**
     * @param string $fsName
     * @param array $contents
     * @param string $fileID
     * @param array $expected
     * @param array $notExpected
     */
    #[DataProvider('listOfFileIDsToNormalise')]
    public function testNormalisePath(
        $fsName,
        array $contents,
        $fileID,
        array $expected,
        array $notExpected = [],
        $expectedFilename = 'folder/file.txt'
    ) {
        /** @var FileIDHelperResolutionStrategy $protectedStrat */
        $protectedStrat = Injector::inst()->get(FileResolutionStrategy::class . '.protected');
        $originalHelpers = $protectedStrat->getResolutionFileIDHelpers();
        $protectedStrat->setResolutionFileIDHelpers(array_merge($originalHelpers, [new NaturalFileIDHelper()]));

        $this->writeDummyFiles($fsName, $contents);

        $results = $this->getBackend()->normalisePath($fileID);

        $this->assertEquals($expectedFilename, $results['Filename']);
        $this->assertTrue(
            strpos(sha1("The quick brown fox jumps over the lazy dog."), $results['Hash'] ?? '') === 0
        );

        $fs = $this->getFilesystem($fsName);

        foreach ($expected as $expectedFile) {
            $this->assertTrue($fs->has($expectedFile), "$expectedFile should exists");
            $this->assertNotEmpty($fs->read($expectedFile), "$expectedFile should be non empty");
        }

        foreach ($notExpected as $notExpectedFile) {
            $this->assertFalse($fs->has($notExpectedFile), "$notExpectedFile should NOT exists");
        }

        $protectedStrat->setResolutionFileIDHelpers($originalHelpers);
    }

    /**
     * @param $fs
     * @return Filesystem
     */
    private function getFilesystem($fs)
    {
        switch (strtolower($fs ?? '')) {
            case AssetStore::VISIBILITY_PUBLIC:
                return $this->getBackend()->getPublicFilesystem();
            case AssetStore::VISIBILITY_PROTECTED:
                return $this->getBackend()->getProtectedFilesystem();
            default:
                new InvalidArgumentException('getFilesystem(): $fs must be an equal to a know visibility.');
        }
    }

    private function writeDummyFiles($fsName, array $contents)
    {
        $fs = $this->getFilesystem($fsName);
        foreach ($contents as $path => $content) {
            $fs->write($path, $content);
        }
    }

    public function testExist()
    {
        // "decade1980" could be confused for an hash because it's 10 hexa-decimal characters
        $filename = 'decade1980/pangram.txt';
        $content = 'The quick brown fox jumps over a lazy dog';
        $hash = sha1($content ?? '');
        $store = $this->getBackend();

        // File haven't been created yet
        $this->assertFalse($store->exists($filename, $hash));
        $this->assertFalse($store->exists($filename, $hash, 'variant'));

        // Create main file on protected store
        $store->setFromString($content, $filename);
        $this->assertTrue($store->exists($filename, $hash));
        $this->assertFalse($store->exists($filename, $hash, 'variant'));

        // Create variant on protected store
        $store->setFromString(strtoupper($content ?? ''), $filename, $hash, 'variant');
        $this->assertTrue($store->exists($filename, $hash, 'variant'));

        // Publish file to public store
        $store->publish($filename, $hash);
        $this->assertTrue($store->exists($filename, $hash));
        $this->assertTrue($store->exists($filename, $hash, 'variant'));

        // Files should be gone
        $store->delete($filename, $hash);
        $this->assertFalse($store->exists($filename, $hash));
        $this->assertFalse($store->exists($filename, $hash, 'variant'));
    }
}

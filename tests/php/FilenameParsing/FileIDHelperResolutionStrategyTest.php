<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class FileIDHelperResolutionStrategyTest extends SapphireTest
{

    protected static $fixture_file = 'FileIDHelperResolutionStrategyTest.yml';

    private $tmpFolder;

    /** @var Filesystem */
    private $fs;

    public function setUp()
    {
        parent::setUp();
        TestAssetStore::activate('FileIDHelperResolutionStrategyTest');

        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        // We're creating an adapter independantly from our AssetStore here, so we can test the Strategy indepentantly
        $this->tmpFolder = tempnam(sys_get_temp_dir(), '');
        unlink($this->tmpFolder);

        $this->fs = new Filesystem(
            new Local($this->tmpFolder)
        );
    }

    public function tearDown()
    {
        TestAssetStore::reset();

        // Clean up our temp adapter
        foreach ($this->fs->listContents() as $fileMeta) {
            if ($fileMeta['type'] === 'dir') {
                $this->fs->deleteDir($fileMeta['path']);
            } else {
                $this->fs->delete($fileMeta['path']);
            }
        }
        rmdir($this->tmpFolder);

        parent::tearDown();
    }

    public function fileList()
    {
        return [
            ['root-file'],
            ['folder-file'],
            ['subfolder-file'],
        ];
    }

    public function fileHelperList()
    {
        $files = $this->fileList();
        $list = [];
        // We're not testing the FileIDHelper implementation here. But there's a bit of split logic based on whatever
        // the FileIDHelper uses the hash or not.
        foreach ($files as $file) {
            $list[] = array_merge($file, [new HashFileIDHelper()]);
            $list[] = array_merge($file, [new NaturalFileIDHelper()]);
        }
        return $list;
    }

    /**
     * This method checks that FileID resolve when access directly.
     * @dataProvider fileHelperList
     */
    public function testDirectResolveFileID($fixtureID, FileIDHelper $helper)
    {
        /** @var File $fileDO */
        $fileDO = $this->objFromFixture(File::class, $fixtureID);

        $fileDO->FileHash = sha1('version 1');
        $fileDO->write();

        $strategy = new FileIDHelperResolutionStrategy();
        $strategy->setDefaultFileIDHelper($helper);
        $strategy->setResolutionFileIDHelpers([$helper]);
        $strategy->setVersionedStage(Versioned::DRAFT);

        $expectedPath = $helper->buildFileID($fileDO->getFilename(), $fileDO->getHash());
        $this->fs->write($expectedPath, 'version 1');

        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertEquals($expectedPath, $redirect->getFileID(), 'Resolution strategy should have found a file.');
        $this->assertEquals($fileDO->getFilename(), $redirect->getFilename());
        $redirect->getHash() && $this->assertTrue(strpos($fileDO->getHash(), $redirect->getHash()) === 0);
    }

    /**
     * This method checks that FileID resolve when their file ID Scheme is a secondary resolution mechanism.
     * @dataProvider fileHelperList
     */
    public function testSecondaryResolveFileID($fixtureID, FileIDHelper $helper)
    {
        /** @var File $fileDO */
        $fileDO = $this->objFromFixture(File::class, $fixtureID);
        $mockHelper = new BrokenFileIDHelper('nonsense.txt', 'nonsense', '', 'nonsense.txt', true, '');

        $fileDO->FileHash = sha1('version 1');
        $fileDO->write();

        $strategy = new FileIDHelperResolutionStrategy();
        $strategy->setDefaultFileIDHelper($mockHelper);
        $strategy->setResolutionFileIDHelpers([$mockHelper, $helper]);
        $strategy->setVersionedStage(Versioned::DRAFT);

        $expectedPath = $helper->buildFileID($fileDO->getFilename(), $fileDO->getHash());
        $this->fs->write($expectedPath, 'version 1');

        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertEquals($expectedPath, $redirect->getFileID(), 'Resolution strategy should have found a file.');
    }

    /**
     * This method checks that resolve fails when there's an hash mismatch
     * @dataProvider fileHelperList
     */
    public function testBadHashResolveFileID($fixtureID, FileIDHelper $helper)
    {
        /** @var File $fileDO */
        $fileDO = $this->objFromFixture(File::class, $fixtureID);
        $mockHelper = new BrokenFileIDHelper('nonsense.txt', 'nonsense', '', 'nonsense.txt', true, '');

        $fileDO->FileHash = sha1('broken content that does not mtach the expected content');
        $fileDO->write();

        $strategy = new FileIDHelperResolutionStrategy();
        $strategy->setDefaultFileIDHelper($mockHelper);
        $strategy->setResolutionFileIDHelpers([$mockHelper, $helper]);
        $strategy->setVersionedStage(Versioned::DRAFT);

        $expectedPath = $helper->buildFileID($fileDO->getFilename(), $fileDO->getHash());
        $this->fs->write($expectedPath, 'version 1');

        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertEquals($expectedPath, $redirect->getFileID(), 'Resolution strategy should have found a file.');
    }


    /**
     * This method checks that FileID resolve when access directly.
     * @dataProvider fileHelperList
     */
    public function testDirectSoftResolveFileID($fixtureID, FileIDHelper $helper)
    {
        /** @var File $fileDO */
        $fileDO = $this->objFromFixture(File::class, $fixtureID);

        $fileDO->FileHash = sha1('version 1');
        $fileDO->write();

        $strategy = new FileIDHelperResolutionStrategy();
        $strategy->setDefaultFileIDHelper($helper);
        $strategy->setResolutionFileIDHelpers([$helper]);
        $strategy->setVersionedStage(Versioned::DRAFT);

        $expectedPath = $helper->buildFileID($fileDO->getFilename(), $fileDO->getHash());
        $this->fs->write($expectedPath, 'version 1');

        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertEquals($expectedPath, $redirect->getFileID(), 'Resolution strategy should have found a file.');
        $this->assertEquals($fileDO->getFilename(), $redirect->getFilename());
        $redirect->getHash() && $this->assertTrue(strpos($fileDO->getHash(), $redirect->getHash()) === 0);
        $this->assertEmpty($fileDO->getVariant());

        $strategy->setVersionedStage(Versioned::LIVE);
        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertNull($redirect, 'Resolution strategy expect file to be published');

        $fileDO->publishSingle();
        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertEquals($expectedPath, $redirect->getFileID(), 'Resolution strategy should have found a file.');
        $this->assertEquals($fileDO->getFilename(), $redirect->getFilename());
        $redirect->getHash() && $this->assertTrue(strpos($fileDO->getHash(), $redirect->getHash()) === 0);
        $this->assertEmpty($fileDO->getVariant());
    }

    /**
     * This method check that older url get redirect to the later ones. This is only relevant for File Scheme with
     * explicit hash. Natural path URL don't change even when the hash of the file does.
     * @dataProvider fileList
     */
    public function testSoftResolveOlderFileID($fixtureID)
    {
        /** @var File $fileDO */
        $fileDO = $this->objFromFixture(File::class, $fixtureID);
        $helper = new HashFileIDHelper();

        $oldHash = $fileDO->FileHash;
        $newerHash = sha1('version 1');
        $fileDO->FileHash = $newerHash;
        $fileDO->write();

        $strategy = new FileIDHelperResolutionStrategy();
        $strategy->setDefaultFileIDHelper($helper);
        $strategy->setResolutionFileIDHelpers([$helper]);
        $strategy->setVersionedStage(Versioned::DRAFT);

        $expectedPath = $helper->buildFileID($fileDO->getFilename(), $newerHash);
        $originalFileID = $helper->buildFileID($fileDO->getFilename(), $oldHash);
        $this->fs->write($expectedPath, 'version 1');

        $redirect = $strategy->softResolveFileID($originalFileID, $this->fs);
        $this->assertEquals($expectedPath, $redirect->getFileID(), 'Resolution strategy should have found a file.');

        $strategy->setVersionedStage(Versioned::LIVE);
        $redirect = $strategy->softResolveFileID($originalFileID, $this->fs);
        $this->assertNull($redirect, 'Resolution strategy expect file to be published');

        $fileDO->publishSingle();
        $redirect = $strategy->softResolveFileID($originalFileID, $this->fs);
        $this->assertNull($redirect, 'The original file never was published so Live resolution should fail');
    }

    /**
     * This method checks that FileID resolve when their file ID Scheme is a secondary resolution mechanism.
     * @dataProvider fileHelperList
     */
    public function testSecondarySoftResolveFileID($fixtureID, FileIDHelper $helper)
    {
        /** @var File $fileDO */
        $fileDO = $this->objFromFixture(File::class, $fixtureID);
        $mockHelper = new BrokenFileIDHelper('nonsense.txt', 'nonsense', '', 'nonsense.txt', true, '');

        $fileDO->FileHash = sha1('version 1');
        $fileDO->write();

        $strategy = new FileIDHelperResolutionStrategy();
        $strategy->setDefaultFileIDHelper($mockHelper);
        $strategy->setResolutionFileIDHelpers([$mockHelper, $helper]);
        $strategy->setVersionedStage(Versioned::DRAFT);

        $expectedPath = $helper->buildFileID($fileDO->getFilename(), $fileDO->getHash());
        $this->fs->write($expectedPath, 'version 1');

        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertEquals($expectedPath, $redirect->getFileID(), 'Resolution strategy should have found a file.');

        $strategy->setVersionedStage(Versioned::LIVE);
        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertNull($redirect, 'Resolution strategy expect file to be published');

        $fileDO->publishSingle();
        $redirect = $strategy->softResolveFileID($expectedPath, $this->fs);
        $this->assertEquals($expectedPath, $redirect->getFileID(), 'Resolution strategy should have found a publish file');
    }

    /**
     * When a file id can be parsed, but that no file can be found, null should be return.
     * @dataProvider fileList
     */
    public function testResolveMissingFileID($fixtureID)
    {
        /** @var File $fileDO */
        $fileDO = $this->objFromFixture(File::class, $fixtureID);
        $brokenHelper = new BrokenFileIDHelper('nonsense.txt', 'nonsense', '', 'nonsense.txt', true, '');
        $mockHelper = new MockFileIDHelper('nonsense.txt', 'nonsense', '', 'nonsense.txt', true, '');

        $fileDO->publishSingle();

        $strategy = new FileIDHelperResolutionStrategy();
        $strategy->setDefaultFileIDHelper($brokenHelper);
        $strategy->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $strategy->setVersionedStage(Versioned::DRAFT);

        $redirect = $strategy->resolveFileID('our/mock/helper/always/resolves.txt', $this->fs);
        $this->assertNull($redirect, 'Theres no file on our adapter for resolveFileID to find');

        $strategy->setVersionedStage(Versioned::LIVE);

        $redirect = $strategy->resolveFileID('our/mock/helper/always/resolves.txt', $this->fs);
        $this->assertNull($redirect, 'Theres no file on our adapter for resolveFileID to find');
    }

    public function searchTupleStrategyVariation()
    {
        $expected = 'expected/abcdef7890/file.txt';

        $brokenHelper = new BrokenFileIDHelper('nonsense.txt', 'nonsense', '', 'nonsense.txt', false, '');
        $mockHelper = new MockFileIDHelper(
            'expected/file.txt',
            substr(sha1('version 1'), 0, 10),
            '',
            $expected,
            true,
            'Folder'
        );

        $parsedFileID = $mockHelper->parseFileID($expected);

        $defaultResolves = new FileIDHelperResolutionStrategy();
        $defaultResolves->setDefaultFileIDHelper($mockHelper);
        $defaultResolves->setResolutionFileIDHelpers([$brokenHelper]);
        $defaultResolves->setVersionedStage(Versioned::DRAFT);

        $secondaryResolves = new FileIDHelperResolutionStrategy();
        $secondaryResolves->setDefaultFileIDHelper($brokenHelper);
        $secondaryResolves->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $secondaryResolves->setVersionedStage(Versioned::DRAFT);

        $secondaryResolvesLive = new FileIDHelperResolutionStrategy();
        $secondaryResolvesLive->setDefaultFileIDHelper($brokenHelper);
        $secondaryResolvesLive->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $secondaryResolvesLive->setVersionedStage(Versioned::LIVE);

        return [
            [$defaultResolves, $parsedFileID, $expected],
            [$secondaryResolves, $parsedFileID, $expected],
            [$secondaryResolvesLive, $parsedFileID, $expected],
            [$secondaryResolves, $parsedFileID->getTuple(), $expected],
            [$secondaryResolvesLive, $parsedFileID->getTuple(), $expected],
        ];
    }

    /**
     * This method checks that FileID resolve when access directly.
     * @dataProvider searchTupleStrategyVariation
     */
    public function testSearchForTuple($strategy, $tuple, $expected)
    {
        $fileID = $strategy->searchForTuple($tuple, $this->fs, false);
        $this->assertNull($fileID, 'There\'s no file on the adapter yet');

        $fileID = $strategy->searchForTuple($tuple, $this->fs, true);
        $this->assertNull($fileID, 'There\'s no file on the adapter yet');

        $this->fs->write($expected, 'version 1');

        $found = $strategy->searchForTuple($tuple, $this->fs, false);
        $this->assertEquals($expected, $found->getFileID(), 'The file has been written');

        $found = $strategy->searchForTuple($tuple, $this->fs, true);
        $this->assertEquals($expected, $found->getFileID(), 'The file has been written');

        $this->fs->put($expected, 'the hash will change and will not match our tuple');

        $found = $strategy->searchForTuple($tuple, $this->fs, false);
        $this->assertEquals(
            $expected,
            $found->getFileID(),
            'With strict set to false, we still find a file even if the hash does not match'
        );

        $found = $strategy->searchForTuple($tuple, $this->fs, true);
        $this->assertNull($found, 'Our file does not match the hash and we asked for a strict hash check');
    }

    public function findVariantsStrategyVariation()
    {
        $brokenHelper = new BrokenFileIDHelper('nonsense.txt', 'nonsense', '', 'nonsense.txt', false, '');
        $mockHelper = new MockFileIDHelper(
            'Folder/FolderFile.pdf',
            substr(sha1('version 1'), 0, 10),
            'mockedvariant',
            'Folder/FolderFile.pdf',
            true,
            'Folder'
        );

        $parsedFileID = $mockHelper->parseFileID('Folder/FolderFile.pdf');

        $defaultResolves = new FileIDHelperResolutionStrategy();
        $defaultResolves->setDefaultFileIDHelper($mockHelper);
        $defaultResolves->setResolutionFileIDHelpers([$brokenHelper]);
        $defaultResolves->setVersionedStage(Versioned::DRAFT);

        $secondaryResolves = new FileIDHelperResolutionStrategy();
        $secondaryResolves->setDefaultFileIDHelper($brokenHelper);
        $secondaryResolves->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $secondaryResolves->setVersionedStage(Versioned::DRAFT);

        $secondaryResolvesLive = new FileIDHelperResolutionStrategy();
        $secondaryResolvesLive->setDefaultFileIDHelper($brokenHelper);
        $secondaryResolvesLive->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $secondaryResolvesLive->setVersionedStage(Versioned::LIVE);

        return [
            [$defaultResolves, $parsedFileID],
            [$secondaryResolves, $parsedFileID],
            [$secondaryResolvesLive, $parsedFileID],
            [$secondaryResolves, $parsedFileID->getTuple()],
            [$secondaryResolvesLive, $parsedFileID->getTuple()],
        ];
    }

    /**
     * This method checks that FileID resolve when access directly.
     * @param FileIDHelperResolutionStrategy $strategy
     * @dataProvider findVariantsStrategyVariation
     */
    public function testFindVariant($strategy, $tuple)
    {
        $this->fs->write('Folder/FolderFile.pdf', 'version 1');
        $this->fs->write('Folder/FolderFile__mockedvariant.pdf', 'version 1 -- mockedvariant');
        $this->fs->write('Folder/SubFolder/SubFolderFile.pdf', 'version 1');
        $this->fs->write('RootFile.txt', 'version 1');

        $expectedPaths = [
            'Folder/FolderFile.pdf',
            'Folder/FolderFile__mockedvariant.pdf',
            'Folder/SubFolder/SubFolderFile.pdf'
        ];

        $variantGenerator = $strategy->findVariants($tuple, $this->fs);
        /** @var ParsedFileID $parsedFileID */
        foreach ($variantGenerator as $parsedFileID) {
            $this->assertNotEmpty($expectedPaths);
            $expectedPath = array_shift($expectedPaths);
            $this->assertEquals($expectedPath, $parsedFileID->getFileID());
            $this->assertEquals('mockedvariant', $parsedFileID->getVariant());
        }

        $this->assertEmpty($expectedPaths);
    }
}

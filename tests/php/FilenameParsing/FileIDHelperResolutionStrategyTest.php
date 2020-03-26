<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Assets\Storage\Sha1FileHashingService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class FileIDHelperResolutionStrategyTest extends SapphireTest
{

    protected static $fixture_file = 'FileIDHelperResolutionStrategyTest.yml';

    private $tmpFolder;

    /** @var Filesystem */
    private $fs;

    protected function setUp() : void
    {
        parent::setUp();
        Sha1FileHashingService::flush();
        TestAssetStore::activate('FileIDHelperResolutionStrategyTest');

        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        // We're creating an adapter independantly from our AssetStore here, so we can test the Strategy indepentantly
        $this->tmpFolder = tempnam(sys_get_temp_dir(), '');
        unlink($this->tmpFolder);

        $this->fs = new Filesystem(
            new Local($this->tmpFolder)
        );
        Injector::inst()->registerService($this->fs, Filesystem::class . '.public');
    }

    protected function tearDown() : void
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
            ['multimedia-file'],
            ['hashfolder-file'],
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

        $strategy = FileIDHelperResolutionStrategy::create();
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

        $strategy = FileIDHelperResolutionStrategy::create();
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

        $strategy = FileIDHelperResolutionStrategy::create();
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

        $strategy = FileIDHelperResolutionStrategy::create();
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

        $strategy = FileIDHelperResolutionStrategy::create();
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

        $strategy = FileIDHelperResolutionStrategy::create();
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

        $strategy = FileIDHelperResolutionStrategy::create();
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

        $hasher = new Sha1FileHashingService();

        $defaultResolves = new FileIDHelperResolutionStrategy();
        $defaultResolves->setDefaultFileIDHelper($mockHelper);
        $defaultResolves->setResolutionFileIDHelpers([$brokenHelper]);
        $defaultResolves->setVersionedStage(Versioned::DRAFT);
        $defaultResolves->setFileHashingService($hasher);

        $secondaryResolves = new FileIDHelperResolutionStrategy();
        $secondaryResolves->setDefaultFileIDHelper($brokenHelper);
        $secondaryResolves->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $secondaryResolves->setVersionedStage(Versioned::DRAFT);
        $secondaryResolves->setFileHashingService($hasher);

        $secondaryResolvesLive = new FileIDHelperResolutionStrategy();
        $secondaryResolvesLive->setDefaultFileIDHelper($brokenHelper);
        $secondaryResolvesLive->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $secondaryResolvesLive->setVersionedStage(Versioned::LIVE);
        $secondaryResolvesLive->setFileHashingService($hasher);

        return [
            'Default Helper' => [$defaultResolves, $parsedFileID, $expected],
            'Resolution Helper' => [$secondaryResolves, $parsedFileID, $expected],
            'Resolution Helper on Live Stage' => [$secondaryResolvesLive, $parsedFileID, $expected],
            'Resolution Helper with Tuple' => [$secondaryResolves, $parsedFileID->getTuple(), $expected],
            'Resolution Helper on Live with Tuple' => [$secondaryResolvesLive, $parsedFileID->getTuple(), $expected],
        ];
    }

    /**
     * This method checks that FileID resolve when access directly.
     * @dataProvider searchTupleStrategyVariation
     */
    public function testSearchForTuple(FileIDHelperResolutionStrategy $strategy, $tuple, $expected)
    {
        /** @var FileHashingService $hasher */
        $hasher = Injector::inst()->get(FileHashingService::class);
        $hasher->disableCache();
        $strategy->setFileHashingService($hasher);

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

    /**
     * SearchForTuple as some weird logic when dealing with Hashless parsed ID
     */
    public function testHashlessSearchForTuple()
    {
        // Set up strategy
        $strategy = FileIDHelperResolutionStrategy::create();
        $hashHelper = new HashFileIDHelper();
        $naturalHelper = new NaturalFileIDHelper();
        $strategy->setDefaultFileIDHelper($hashHelper);
        $strategy->setResolutionFileIDHelpers([$hashHelper, $naturalHelper]);
        $strategy->setVersionedStage(Versioned::DRAFT);

        // Set up some dummy file
        $content = "The quick brown fox jumps over the lazy dog.";
        $hash = sha1($content);
        $filename = 'folder/file.txt';
        $variant = 'uppercase';
        $dbFile = new File(['FileFilename' => $filename, 'FileHash' => $hash]);
        $fs = $this->fs;

        // Set up paths
        $pfID = new ParsedFileID($filename, '', '');
        $variantPfID = new ParsedFileID($filename, '', $variant);
        $naturalPath = $naturalHelper->buildFileID($filename, $hash);
        $hashPath = $hashHelper->buildFileID($filename, $hash);
        $variantNaturalPath = $naturalHelper->buildFileID($filename, $hash, $variant);
        $variantHashPath = $hashHelper->buildFileID($filename, $hash, $variant);

        // No file yet
        $this->assertNull($strategy->searchForTuple($pfID, $fs));
        $this->assertNull($strategy->searchForTuple($variantPfID, $fs));

        // Looking for a natural path file not in DB
        $fs->write($naturalPath, $content);

        $respPfID = $strategy->searchForTuple($pfID, $fs);
        $this->assertNotNull($respPfID);
        $this->assertEquals($hash, $respPfID->getHash());
        $this->assertNull($strategy->searchForTuple($variantPfID, $fs));

        // Looking for a natural path variant file not in DB
        $fs->write($variantNaturalPath, strtoupper($content));

        $respPfID = $strategy->searchForTuple($variantPfID, $fs);
        $this->assertNotNull($respPfID);
        $this->assertEquals($hash, $respPfID->getHash(), 'hash should have been read from main file');

        // Looking for hash path of file NOT in DB
        $fs->rename($naturalPath, $hashPath);
        $fs->rename($variantNaturalPath, $variantHashPath);
        $this->assertNull($strategy->searchForTuple($pfID, $fs), 'strategy does not know in what folder to look');
        $this->assertNull($strategy->searchForTuple($variantPfID, $fs), 'strategy does not know in what folder to look');

        // Looking for hash path of file IN DB
        $dbFile->write();

        $respPfID = $strategy->searchForTuple($pfID, $fs);
        $this->assertNotNull($respPfID);
        $this->assertEquals($hash, $respPfID->getHash(), 'Should have found the hash in the DB and found the file');

        $respPfID = $strategy->searchForTuple($variantPfID, $fs);
        $this->assertNotNull($respPfID);
        $this->assertEquals($hash, $respPfID->getHash(), 'hash should have been read from main file');

        // Looking for hash path of file IN DB but not in targeted stage
        $strategy->setVersionedStage(Versioned::LIVE);
        $this->assertNull($strategy->searchForTuple($pfID, $fs), 'strategy should only look at live record');
        $this->assertNull($strategy->searchForTuple($variantPfID, $fs), 'strategy should only look at live record');

        // Looking for hash path of file IN DB and IN targeted stage
        $dbFile->publishSingle();

        $respPfID = $strategy->searchForTuple($pfID, $fs);
        $this->assertNotNull($respPfID);
        $this->assertEquals($hash, $respPfID->getHash(), 'Should have found the hash in the DB and found the file');

        $respPfID = $strategy->searchForTuple($variantPfID, $fs);
        $this->assertNotNull($respPfID);
        $this->assertEquals($hash, $respPfID->getHash(), 'hash should have been read from main file');
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

        $hasher = new Sha1FileHashingService();

        $defaultResolves = new FileIDHelperResolutionStrategy();
        $defaultResolves->setDefaultFileIDHelper($mockHelper);
        $defaultResolves->setResolutionFileIDHelpers([$brokenHelper]);
        $defaultResolves->setVersionedStage(Versioned::DRAFT);
        $defaultResolves->setFileHashingService($hasher);

        $secondaryResolves = new FileIDHelperResolutionStrategy();
        $secondaryResolves->setDefaultFileIDHelper($brokenHelper);
        $secondaryResolves->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $secondaryResolves->setVersionedStage(Versioned::DRAFT);
        $secondaryResolves->setFileHashingService($hasher);

        $secondaryResolvesLive = new FileIDHelperResolutionStrategy();
        $secondaryResolvesLive->setDefaultFileIDHelper($brokenHelper);
        $secondaryResolvesLive->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $secondaryResolvesLive->setVersionedStage(Versioned::LIVE);
        $secondaryResolvesLive->setFileHashingService($hasher);

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

    public function testFindHashlessVariant()
    {
        $strategy = FileIDHelperResolutionStrategy::create();
        $strategy->setDefaultFileIDHelper($naturalHelper = new NaturalFileIDHelper());
        $strategy->setResolutionFileIDHelpers([new HashFileIDHelper()]);

        $expectedHash = sha1('version 1');

        $this->fs->write('Folder/FolderFile.pdf', 'version 1');
        $this->fs->write(
            sprintf('Folder/%s/FolderFile.pdf', substr($expectedHash, 0, 10)),
            'version 1'
        );
        $this->fs->write('Folder/FolderFile__mockedvariant.pdf', 'version 1 -- mockedvariant');

        $expectedPaths = [
            ['Folder/FolderFile.pdf', ''],
            ['Folder/FolderFile__mockedvariant.pdf', 'mockedvariant']
            // The hash path won't be match, because we're not providing a hash
        ];

        $variantGenerator = $strategy->findVariants(new ParsedFileID('Folder/FolderFile.pdf'), $this->fs);

        /** @var ParsedFileID $parsedFileID */
        foreach ($variantGenerator as $parsedFileID) {
            $this->assertNotEmpty($expectedPaths, 'More files were returned than expected');
            $expectedPath = array_shift($expectedPaths);
            $this->assertEquals($expectedPath[0], $parsedFileID->getFileID());
            $this->assertEquals($expectedPath[1], $parsedFileID->getVariant());
            $this->assertEquals($expectedHash, $parsedFileID->getHash());
        }

        $this->assertEmpty($expectedPaths, "Not all expected files were returned");
    }

    public function testParseFileID()
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

        $strategy = FileIDHelperResolutionStrategy::create();

        // Test that file ID gets resolved properly if a functional helper is provided
        $strategy->setResolutionFileIDHelpers([$brokenHelper, $mockHelper]);
        $parsedFileID = $strategy->parseFileID('alpha/bravo.charlie');
        $this->assertNotEmpty($parsedFileID);
        $this->assertEquals('Folder/FolderFile.pdf', $parsedFileID->getFilename());

        // Test that null is returned if no helper can parsed the file ID
        $strategy->setResolutionFileIDHelpers([$brokenHelper]);
        $parsedFileID = $strategy->parseFileID('alpha/bravo.charlie');
        $this->assertEmpty($parsedFileID);
    }

    public function listVariantwihtoutFileID()
    {
        $content = "The quick brown fox jumps over the lazy dog.";
        $hash = sha1($content);
        $filename = 'folder/file.txt';
        $variant = 'uppercase';
        $pfID = new ParsedFileID($filename, $hash);
        $variantPfid = $pfID->setVariant($variant);
        $naturalHelper = new NaturalFileIDHelper();
        $naturalPath = $naturalHelper->buildFileID($filename, $hash);
        $hashHelper = new HashFileIDHelper();
        $hashPath = $hashHelper->buildFileID($filename, $hash);

        return [
            [$naturalPath, $content, $pfID, $naturalPath],
            [$hashPath, $content, $pfID, $hashPath],
            [$naturalPath, $content, $variantPfid, $naturalHelper->buildFileID($variantPfid)],
            [$hashPath, $content, $variantPfid, $hashHelper->buildFileID($variantPfid)],
            ['non/exisitant/file.txt', $content, $variantPfid, null],
            [$naturalPath, 'bad hash', $variantPfid, null],
            [$hashPath, 'bad hash', $variantPfid, null],
        ];
    }

    /**
     * @dataProvider listVariantwihtoutFileID
     */
    public function testGenerateVariantFileID($mainFilePath, $content, ParsedFileID $variantPfid, $expectedFileID)
    {
        /** @var FileResolutionStrategy $strategy */
        $strategy = Injector::inst()->get(FileResolutionStrategy::class . '.public');
        $this->fs->write($mainFilePath, $content);

        $responsePfid = $strategy->generateVariantFileID($variantPfid, $this->fs);
        if ($expectedFileID) {
            $this->assertEquals($expectedFileID, $responsePfid->getFileID());
        } else {
            $this->assertNull($responsePfid);
        }
    }

    public function listVariantParsedFiledID()
    {
        $pfid = new ParsedFileID('folder/file.txt', 'abcdef7890');
        $ambigious = new ParsedFileID('decade1980/file.txt', 'abcdef7890');
        return [
            'Variantless Natural path' =>
                [$pfid->setFileID('folder/file.txt')->setHash(''), 'folder/file.txt'],
            'Variantless Hash path' =>
                [$pfid->setFileID('folder/abcdef7890/file.txt'), 'folder/abcdef7890/file.txt'],
            'Variant natural path' =>
                [$pfid->setFileID('folder/file.txt')->setHash(''), 'folder/file__variant.txt'],
            'Variant hash path' =>
                [$pfid->setFileID('folder/abcdef7890/file.txt'), 'folder/abcdef7890/file__variant.txt'],

            'Variantless Natural path with ParsedFileID with undefined file ID' =>
                [$pfid->setFileID('folder/file.txt'), $pfid],
            'Variantless natural path with ParsedFileID with defined file ID' =>
                [$pfid->setFileID('folder/file.txt'), $pfid->setFileID('folder/file.txt')],
            'Variantless hash path with ParsedFileID with defined FileID' =>
                [$pfid->setFileID('folder/abcdef7890/file.txt'), $pfid->setFileID('folder/abcdef7890/file.txt')],
            'Natural path with ParsedFileID with defined FileID' =>
                [$pfid->setFileID('folder/file.txt'), $pfid->setFileID('folder/file__variant.txt')],
            'Hash path with ParsedFileID with defined FileID' =>
                [
                    $pfid->setFileID('folder/abcdef7890/file.txt'),
                    $pfid->setFileID('folder/abcdef7890/file__variant.txt')
                ],
            'File in folder that could be an hash' => [
                $ambigious->setFileID('decade1980/file.txt'),
                $ambigious->setFileID('decade1980/file.txt'),
            ],
            'File variant in folder that could be an hash' => [
                $ambigious->setFileID('decade1980/file.txt'),
                $ambigious->setFileID('decade1980/file__variant.txt')->setVariant('variant'),
            ]
        ];
    }

    /**
     * @dataProvider listVariantParsedFiledID
     */
    public function testStripVariant(ParsedFileID $expected, $input)
    {
        /** @var FileResolutionStrategy $strategy */
        $strategy = Injector::inst()->get(FileResolutionStrategy::class . '.public');

        $actual = $strategy->stripVariant($input);

        if ($expected) {
            $this->assertEquals($expected->getFilename(), $actual->getFilename());
            $this->assertEquals($expected->getHash(), $actual->getHash());
            $this->assertEmpty($actual->getVariant());
            $this->assertEquals($expected->getFileID(), $actual->getFileID());
        } else {
            $this->assertNull($actual);
        }
    }
}

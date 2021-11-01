<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Dev\Tasks\FileMigrationHelper;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Assets\Tests\Dev\Tasks\FileMigrationHelperTest\Extension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * Ensures that File dataobjects can be safely migrated from 3.x
 */
class FileMigrationHelperTest extends SapphireTest
{
    protected $usesTransactions = false;

    protected static $fixture_file = 'FileMigrationHelperTest.yml';

    private $expectedContent = [];

    protected static $required_extensions = [
        File::class => [
            Extension::class,
        ]
    ];

    /**
     * get the BASE_PATH for this test
     *
     * @return string
     */
    protected function getBasePath()
    {
        // Note that the actual filesystem base is the 'assets' subdirectory within this
        return ASSETS_PATH . '/FileMigrationHelperTest';
    }


    protected function setUp(): void
    {
        parent::setUp();

        // Set backend root to /FileMigrationHelperTest/assets
        TestAssetStore::activate('FileMigrationHelperTest/assets');
        $this->makingBadFilesBadAgain();

        /** @var \League\Flysystem\Filesystem $fs */
        $fs = Injector::inst()->get(AssetStore::class)->getPublicFilesystem();

        // Ensure that each file has a local record file in this new assets base
        $missingID = $this->idFromFixture(File::class, 'missing-file');
        foreach (File::get()->filter('ClassName', File::class)->exclude('ID', $missingID) as $file) {
            $filename = $file->generateFilename();
            $this->expectedContent[$file->ID] = 'Content of ' . $filename;
            $fs->write($filename, $this->expectedContent[$file->ID]);
        }

        // Let's create some variants for our images
        $fromMain = fopen(__DIR__ . '/../../ImageTest/test-image-low-quality.jpg', 'r');
        $fromVariant = fopen(__DIR__ . '/../../ImageTest/test-image-high-quality.jpg', 'r');
        foreach (Image::get() as $file) {
            $filename = $file->generateFilename();
            rewind($fromMain);
            $fs->write($filename, $fromMain);

            $dir = dirname($filename);
            $basename = basename($filename);

            rewind($fromVariant);
            $fs->write($dir . '/_resampled/resizeXYZ/' . $basename, $fromVariant);
            rewind($fromVariant);
            $fs->write($dir . '/_resampled/resizeXYZ/scaleABC/' . $basename, $fromVariant);
            rewind($fromVariant);
            $fs->write($dir . '/_resampled/ScaleWidthWzEwMF0-' . $basename, $fromVariant);
            rewind($fromVariant);
            $fs->write($dir . '/_resampled/ScaleWidthWzEwMF0-FitWzEwMCwxMDBd-' . $basename, $fromVariant);
        }
        fclose($fromMain);
        fclose($fromVariant);

        $fs->rename('wrong-case.txt', 'wRoNg-CaSe.tXt');
        $fs->rename('Uploads/good-case-bad-folder.txt', 'uploads/good-case-bad-folder.txt');
        $fs->copy('too-many-alternative-case.txt', 'Too-Many-Alternative-Case.txt');
        $fs->copy('too-many-alternative-case.txt', 'Too-Many-Alternative-Case.TXT');
        $fs->delete('too-many-alternative-case.txt');
    }

    /**
     * When setUp creates fixtures, they go through their normal validation process. This means that our bad file names
     * get cleaned up before being written to the DB. We need them to be bad in the DB, so we'll run a bunch of manual
     * queries to bypass the ORM.
     */
    public function makingBadFilesBadAgain()
    {
        // Renaming our common bad files
        $badnameIDs = array_map(
            function ($args) {
                list($class, $identifier) = $args;
                return $this->idFromFixture($class, $identifier);
            },
            [
                [File::class, 'badname'],
                [File::class, 'badname2'],
                [Image::class, 'badimage']
            ]
        );
        SQLUpdate::create(
            '"File"',
            [
                '"Filename"' => ['REPLACE("Filename",?,?)' => ['_', '__']],
                '"Name"' => ['REPLACE("Name",?,?)' => ['_', '__']],
            ],
            ['"ID" IN (?,?,?)' => $badnameIDs]
        )->execute();

        // badnameconflict needs to be manually updated, because it will have gotten a `-v2` suffix
        SQLUpdate::create(
            '"File"',
            [
                '"Filename"' => 'assets/bad__name.doc',
                '"Name"' => 'bad__name.doc',
            ],
            ['"ID"' => $this->idFromFixture(File::class, 'badnameconflict')]
        )->execute();

        SQLUpdate::create(
            '"File"',
            [
                '"Filename"' => 'assets/ParentFolder/SubFolder/multi-dash--file---4.pdf',
                '"Name"' => 'multi-dash--file---4.pdf',
            ],
            ['"ID"' => $this->idFromFixture(File::class, 'multi-dash-file')]
        )->execute();

        SQLUpdate::create(
            '"File"',
            [
                '"Filename"' => 'assets/mixed-case-file.txt',
                '"Name"' => 'mixed-case-file.txt',
            ],
            ['"ID"' => $this->idFromFixture(File::class, 'all-lowercase')]
        )->execute();

        SQLUpdate::create(
            '"File"',
            [
                '"Filename"' => 'assets/uploads/good-case-bad-folder.txt',
            ],
            ['"ID"' => $this->idFromFixture(File::class, 'mismatch-folder-case')]
        )->execute();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
    }

    /**
     * Test file migration
     */
    public function testMigration()
    {
        $this->preCondition();

        // The EXE file, extensionless file and missing file won't be migrated
        $expectNumberOfMigratedFiles = File::get()->exclude('ClassName', Folder::class)->count() - 4;

        // Do migration
        $helper = new FileMigrationHelper();
        $result = $helper->run($this->getBasePath());

        // Test the top level results
        $this->assertEquals($expectNumberOfMigratedFiles, $result);

        // Test that each file exists excluding conflictual file
        $files = File::get()
            ->exclude('ClassName', Folder::class)
            ->exclude('ID', [
                $this->idFromFixture(File::class, 'goodnameconflict'),
                $this->idFromFixture(File::class, 'badnameconflict'),
                $this->idFromFixture(File::class, 'multi-dash-file'),
                $this->idFromFixture(File::class, 'missing-file'),
                $this->idFromFixture(File::class, 'too-many-case'),
            ]);

        foreach ($files as $file) {
            $this->individualFiles($file);
        }

        foreach (Image::get() as $image) {
            $this->individualImage($image);
        }

        $this->invalidFile();
        $this->fileWithNoExtension();
        $this->pdfNormalisedToFile();
        $this->badSS3filenames();
        $this->conflictualBadNames();
        $this->missingFiles();
    }

    /**
     * Testing that our set up script has worked as expected.
     */
    private function preCondition()
    {
        // Prior to migration, check that each file has empty Filename / Hash properties
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $filename = $file->generateFilename();
            $this->assertNotEmpty($filename, "File {$file->Name} has a filename");
            $this->assertEmpty($file->File->getFilename(), "File {$file->Name} has no DBFile filename");
            $this->assertEmpty($file->File->getHash(), "File {$file->Name} has no hash");
            $this->assertFalse($file->exists(), "File with name {$file->Name} does not yet exist");
            $this->assertFalse($file->isPublished(), "File is not published yet");
        }

        $this->assertFileExists(
            TestAssetStore::base_path() . '/ParentFolder/SubFolder/myfile.exe',
            'We should have an invalid file before going into the migration.'
        );
    }

    /**
     * Check the validity of an individual files
     * @param File $file
     */
    private function individualFiles(File $file)
    {
        $expectedFilename = $file->generateFilename();
        $filename = $file->File->getFilename();
        $this->assertTrue($file->exists(), "File with name {$filename} exists");
        $this->assertNotEmpty($filename, "File {$file->Name} has a Filename");
//        $this->assertEquals($expectedFilename, $filename, "File {$file->Name} has retained its Filename value");

        // myimage.pdf starts off as an image, that's why it will have the image hash
        if ($file->ClassName == Image::class || $expectedFilename == 'myimage.pdf') {
            $this->assertEquals(
                '33be1b95cba0358fe54e8b13532162d52f97421c',
                $file->File->getHash(),
                "File with name {$filename} has the correct hash"
            );
        } else {
            $expectedContent = $this->expectedContent[$file->ID];
            $this->assertEquals(
                $expectedContent,
                $file->getString(),
                "File with name {$filename} has the correct content"
            );
            $this->assertEquals(
                sha1($expectedContent),
                $file->File->getHash(),
                "File with name {$filename} has the correct hash"
            );
        }

        $this->assertTrue($file->isPublished(), "File is published after migration");
        $this->assertGreaterThan(0, $file->getAbsoluteSize());
    }

    /**
     * Checking the validity of an individual image
     * @param Image $file
     */
    private function individualImage(Image $file)
    {
        // Test that our image variant got moved correctly
        $fullFilename = TestAssetStore::base_path() . '/' . $file->getFilename();
        $dir = dirname($fullFilename);
        $baseFilename = basename($fullFilename);
        $this->assertFileDoesNotExist($dir . '/_resampled');
        $this->assertFileExists($dir . '/' . $baseFilename);

        // Test that SS3.3 variants have been migrated
        $variantFilename = preg_replace('#^(.*)\.(.*)$#', '$1__resizeXYZ.$2', $baseFilename);
        $this->assertFileExists($dir . '/' . $variantFilename);
        $variantFilename = preg_replace('#^(.*)\.(.*)$#', '$1_scaleABC.$2', $variantFilename);
        $this->assertFileExists($dir . '/' . $variantFilename);

        // Test that pre SS3.0 variants have been migrated
        $variantFilename = preg_replace('#^(.*)\.(.*)$#', '$1__ScaleWidthWzEwMF0.$2', $baseFilename);
        $this->assertFileExists($dir . '/' . $variantFilename);
        $variantFilename = preg_replace('#^(.*)\.(.*)$#', '$1_FitWzEwMCwxMDBd.$2', $variantFilename);
        $this->assertFileExists($dir . '/' . $variantFilename);
    }

    /**
     * Ensure that invalid file has been removed during migration. One EXE file should have been removed from the DB
     */
    private function invalidFile()
    {
        $invalidID = $this->idFromFixture(File::class, 'invalid');
        $this->assertNotEmpty($invalidID);
        $this->assertNull(File::get()->byID($invalidID));
    }
    /**
     * Ensure that invalid file has been removed during migration. One file without an extension should have been removed from the DB
     */
    private function fileWithNoExtension()
    {
        $invalidID = $this->idFromFixture(File::class, 'no-extension');
        $this->assertNotEmpty($invalidID);
        $this->assertNull(File::get()->byID($invalidID));
    }

    /**
     * SS2.4 considered PDFs to be images. We should convert that back to Regular files
     */
    private function pdfNormalisedToFile()
    {
        //
        $pdf = File::find('myimage.pdf');
        $this->assertEquals(File::class, $pdf->ClassName, 'Our PDF classnames should have been corrrected');
    }

    /**
     * Files with double underscores in their name should have been renamed to have single underscores.
     */
    private function badSS3filenames()
    {
        // Test that SS3 files with invalid SS4 names, get correctly rename
        /** @var File $badname */
        $badname = $this->objFromFixture(File::class, 'badname');
        $this->assertEquals('ParentFolder/bad_name.zip', $badname->getFilename());
        $this->assertFileExists(TestAssetStore::base_path() . '/ParentFolder/bad_name.zip');
        $badname2 = $this->objFromFixture(File::class, 'badname2');
        $this->assertEquals('bad_0.zip', $badname2->getFilename());
        $this->assertFileExists(TestAssetStore::base_path() . '/bad_0.zip');
        $badimage = $this->objFromFixture(Image::class, 'badimage');
        $this->assertEquals('bad_image.gif', $badimage->getFilename());
        $this->assertFileExists(TestAssetStore::base_path() . '/bad_image.gif');
        $this->assertFileExists(TestAssetStore::base_path() . '/bad_image__resizeXYZ.gif');
        $this->assertFileExists(TestAssetStore::base_path() . '/bad_image__resizeXYZ_scaleABC.gif');

        // Test that our multi dash filename that would normally be renamed via the front end is still the same
        $badname = $this->objFromFixture(File::class, 'multi-dash-file');
        $this->assertEquals('ParentFolder/SubFolder/multi-dash--file---4.pdf', $badname->getFilename());
        $this->assertFileExists(TestAssetStore::base_path() . '/ParentFolder/SubFolder/multi-dash--file---4.pdf');
    }

    /**
     * If you have a bad files who would otherwise be renamed to an existing file, it should get a `-v2` suffix.
     */
    private function conflictualBadNames()
    {

        $good = $this->objFromFixture(File::class, 'goodnameconflict');
        $bad = $this->objFromFixture(File::class, 'badnameconflict');

        // Test the names and existence
        $this->assertEquals('bad_name.doc', $good->getFilename());
        $this->assertEquals('bad_name-v2.doc', $bad->getFilename());
        $this->assertTrue($good->exists(), 'bad_name.doc should exist');
        $this->assertTrue($bad->exists(), 'bad_name-v2.doc should exist');

        // Test the content and hashes of the files
        $expectedGoodContent = 'Content of bad_name.doc';
        $this->assertEquals(
            $expectedGoodContent,
            $good->getString(),
            "bad_name.doc has the expected content"
        );
        $this->assertEquals(
            sha1($expectedGoodContent),
            $good->File->getHash(),
            "bad_name.doc has the expected hash"
        );

        $expectedBadContent = 'Content of bad__name.doc';
        $this->assertEquals(
            $expectedBadContent,
            $bad->getString(),
            "bad_name-v2.doc has the expected content"
        );
        $this->assertEquals(
            sha1($expectedBadContent),
            $bad->File->getHash(),
            "bad_name-v2.doc has the expected hash"
        );
    }

    /**
     * That that files that could not be migrated are still there.
     */
    private function missingFiles()
    {
        $missingID = $this->idFromFixture(File::class, 'missing-file');
        $missing = File::get()->byID($missingID);
        $this->assertNotEmpty($missing, 'Missing file DB entry should still be there');
        $this->assertEmpty($missing->FileFilename, 'Missing file DB entry should not have a FileFilename');

        $tooManyCaseID = $this->idFromFixture(File::class, 'too-many-case');
        $tooManyCase = File::get()->byID($tooManyCaseID);
        $this->assertNotEmpty($tooManyCase, 'file DB entry that could not be migrated should still be there');
        $this->assertEmpty(
            $tooManyCase->FileFilename,
            'file DB entry that could not be migrated should not have FileFilename'
        );

        /** @var Filesystem $fs */
        $fs = Injector::inst()->get(AssetStore::class)->getPublicFilesystem();
        $this->assertTrue(
            $fs->has('Too-Many-Alternative-Case.txt'),
            'Too-Many-Alternative-Case.txt should still be there'
        );$this->assertTrue(
            $fs->has('Too-Many-Alternative-Case.TXT'),
            'Too-Many-Alternative-Case.TXT should still be there'
        );
    }

    /**
     * Run the same battery of test but with legacy file name enabled.
     */
    public function testMigrationWithLegacyFilenames()
    {
        Config::modify()->set(FlysystemAssetStore::class, 'legacy_filenames', true);
        $this->testMigration();
    }

    /**
     * If you're using a public file resolution strategy that doesn't use the LegacyFileMigration, files should not be
     * migrated.
     */
    public function testInvalidAssetStoreStrategy()
    {
        $strategy = FileIDHelperResolutionStrategy::create();
        $strategy->setDefaultFileIDHelper(new HashFileIDHelper());
        $strategy->setResolutionFileIDHelpers([new HashFileIDHelper()]);

        $store = Injector::inst()->get(AssetStore::class);
        $store->setPublicResolutionStrategy($strategy);

        // Do migration
        $helper = new FileMigrationHelper();
        $result = $helper->run($this->getBasePath());

        // Test the top level results
        $this->assertEquals(0, $result);
    }

    /**
     * Run the same battery of test but with legacy file name enabled.
     */
    public function testCacheFileHashes()
    {
        /** @var FileHashingService $hasher */
        $hasher = Injector::inst()->get(FileHashingService::class);
        $hasher->enableCache();
        $this->testMigration();
    }
}

<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Dev\Tasks\FileMigrationHelper;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Tests\Dev\Tasks\FileMigrationHelperTest\Extension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Ensures that File dataobjects can be safely migrated from 3.x
 */
class FileMigrationHelperTest extends SapphireTest
{
    protected $usesTransactions = false;

    protected static $fixture_file = 'FileMigrationHelperTest.yml';

    protected static $required_extensions = array(
        File::class => array(
            Extension::class,
        )
    );

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


    public function setUp()
    {
        Config::nest(); // additional nesting here necessary
        Config::modify()->merge(File::class, 'migrate_legacy_file', false);
        parent::setUp();

        // Set backend root to /FileMigrationHelperTest/assets
        TestAssetStore::activate('FileMigrationHelperTest/assets');

        // Ensure that each file has a local record file in this new assets base
        $from = __DIR__ . '/../../ImageTest/test-image-low-quality.jpg';
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $dest = TestAssetStore::base_path() . '/' . $file->generateFilename();
            Filesystem::makeFolder(dirname($dest));
            copy($from, $dest);
        }

        // Let's create some variants for our images
        $from = __DIR__ . '/../../ImageTest/test-image-high-quality.jpg';
        foreach (Image::get() as $file) {
            $dest = TestAssetStore::base_path() . '/' . $file->generateFilename();
            $dir = dirname($dest);
            $basename = basename($dest);
            Filesystem::makeFolder($dir . '/_resampled');
            Filesystem::makeFolder($dir . '/_resampled/resizeXYZ');
            Filesystem::makeFolder($dir . '/_resampled/resizeXYZ/scaleABC');
            copy($from, $dir . '/_resampled/resizeXYZ/' . $basename);
            copy($from, $dir . '/_resampled/resizeXYZ/scaleABC/' . $basename);
        }
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
        Config::unnest();
    }

    /**
     * Test file migration
     */
    public function testMigration()
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

        //
        $this->assertFileExists(
            TestAssetStore::base_path() . '/ParentFolder/SubFolder/myfile.exe',
            'We should have an invalid file before going into the migration.'
        );

        // Do migration
        $helper = new FileMigrationHelper();
        $result = $helper->run($this->getBasePath());
        $this->assertEquals(7, $result);

        // Test that each file exists
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            /** @var File $file */
            $expectedFilename = $file->generateFilename();
            $filename = $file->File->getFilename();
            $this->assertTrue($file->exists(), "File with name {$filename} exists");
            $this->assertNotEmpty($filename, "File {$file->Name} has a Filename");
            $this->assertEquals($expectedFilename, $filename, "File {$file->Name} has retained its Filename value");
            $this->assertEquals(
                '33be1b95cba0358fe54e8b13532162d52f97421c',
                $file->File->getHash(),
                "File with name {$filename} has the correct hash"
            );
            $this->assertTrue($file->isPublished(), "File is published after migration");
            $this->assertGreaterThan(0, $file->getAbsoluteSize());
        }

        // Test that our image variant got moved correctly
        foreach (Image::get() as $file) {
            $filename = TestAssetStore::base_path() . '/' . $file->getFilename();
            $dir = dirname($filename);
            $filename = basename($filename);
            $this->assertFileNotExists($dir . '/_resampled');
            $this->assertFileExists($dir . '/' . $filename);

            $filename = preg_replace('#^(.*)\.(.*)$#', '$1__resizeXYZ.$2', $filename);
            $this->assertFileExists($dir . '/' . $filename);

            $filename = preg_replace('#^(.*)\.(.*)$#', '$1_scaleABC.$2', $filename);
            $this->assertFileExists($dir . '/' . $filename);
        }

        // Ensure that invalid file has been removed during migration
        $invalidID = $this->idFromFixture(File::class, 'invalid');
        $this->assertNotEmpty($invalidID);
        $this->assertNull(File::get()->byID($invalidID));

        # TODO confirm if we should delete the physical invalid file as well
//        $this->assertFileNotExists(
//            TestAssetStore::base_path() . '/ParentFolder/SubFolder/myfile.exe' ,
//            'Invalid file should have been removed by migration'
//        );

        // Ensure file with invalid filenames have been rename
        /** @var File $badname */
        $badname = $this->objFromFixture(File::class, 'badname');
        $this->assertEquals(
            'ParentFolder/bad_name.zip',
            $badname->getFilename(),
            'file names with invalid file name should have been cleaned up'
        );

        // SS2.4 considered PDFs to be images. We should convert that back to Regular files
        $pdf = File::find('myimage.pdf');
        $this->assertEquals(File::class, $pdf->ClassName, 'Our PDF classnames should have been corrrected');
    }

    public function testMigrationWithLegacyFilenames()
    {
        Config::modify()->set(FlysystemAssetStore::class, 'legacy_filenames', true);
        $this->testMigration();
    }
}

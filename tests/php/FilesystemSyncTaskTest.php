<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\AssetControlExtension;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\FilesystemSyncTaskHelper;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Yaml\Yaml;

/**
 * Ensures that files and directories in the assets folder can be synced with the database.
 */
class FilesystemSyncTaskTest extends SapphireTest
{
    protected static $configFilename = 'FilesystemSyncTaskHelperTest.yml';

    /**
     * @return string
     */
    protected function getBasePath()
    {
        // Note that the actual filesystem base is the 'assets' subdirectory within this
        return ASSETS_PATH . '/FilesystemSyncTaskHelperTest';
    }


    public function setUp()
    {
        Config::nest(); // additional nesting here necessary
        parent::setUp();

        // Set backend root to /FilesystemSyncTaskHelperTest/assets
        TestAssetStore::activate('FilesystemSyncTaskHelperTest/assets');

        // Ensure that each file has a local record file in this new assets base
        $config = Yaml::parse(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . static::$configFilename));
        $from = __DIR__ . '/ImageTest/test-image-low-quality.jpg';
        foreach ($config['Files'] as $class => $files) {
            if ($class == Folder::class) {
                continue;
            }

            foreach ($files as $file) {
                $dest =
                    TestAssetStore::base_path() . DIRECTORY_SEPARATOR .
                    (isset($file['Parent']) ? $file['Parent'] . DIRECTORY_SEPARATOR : '') .
                    $file['Name']
                ;
                Filesystem::makeFolder(dirname($dest));
                copy($from, $dest);
            }
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
     * Test file sync
     */
    public function testSync()
    {
        // Run sync task
        $helper = new FilesystemSyncTaskHelper();
        $result = $helper->run([
            'path' => ''
        ]);

        // Check correct results are given
        $this->assertEquals(5, $result['filesSyncedWithDb'], 'Files synced with database');
        $this->assertEquals(2, $result['dirsSyncedWithDb'], 'Folders synced with database');
        $this->assertEquals(0, $result['filesRemovedFromDb'], 'No files removed from database');
        $this->assertEquals(0, $result['filesRemovedFromFilesystem'], 'No files removed from filesystem');
        $this->assertEquals(0, $result['filesSkippedFromFilesystem'], 'No files skipped from filesystem');

        // Test that each file exists
        /** @var File $file */
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $expectedFilename = $file->generateFilename();
            $filename = $file->File->getFilename();
            $this->assertTrue($file->exists(), "File with name {$file->Name} exists");
            $this->assertNotEmpty($filename, "File {$file->Name} has a Filename");
            $this->assertEquals($expectedFilename, $filename, "File {$file->Name} has retained its Filename value");
            $this->assertEquals(
                '33be1b95cba0358fe54e8b13532162d52f97421c',
                $file->File->getHash(),
                "File with name {$filename} has the correct hash"
            );
            $this->assertTrue($file->isPublished(), "File is published after sync");
            $this->assertGreaterThan(0, $file->getAbsoluteSize());
        }
    }

    public function testSyncLegacyFileNames()
    {
        Config::modify()->set(FlysystemAssetStore::class, 'legacy_filenames', true);
        $this->testSync();
        Config::modify()->set(FlysystemAssetStore::class, 'legacy_filenames', false);
    }

    public function testSyncKeepArchivedAssets()
    {
        Config::modify()->set(AssetControlExtension::class, 'keep_archived_assets', true);
        $this->testSync();
        Config::modify()->set(AssetControlExtension::class, 'keep_archived_assets', false);
    }
}

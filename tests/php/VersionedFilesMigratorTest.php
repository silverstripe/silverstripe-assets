<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Dev\Tasks\VersionedFilesMigrationTask;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Dev\VersionedFilesMigrator;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FileMigrationHelper;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Tests\FileMigrationHelperTest\Extension;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Path;
use SilverStripe\Dev\SapphireTest;

class VersionedFilesMigratorTest extends SapphireTest
{
    protected $usesTransactions = false;

    /**
     * get the BASE_PATH for this test
     *
     * @return string
     */
    protected function getBasePath()
    {
        return ASSETS_PATH . '/VersionedFilesMigrationTest';
    }


    public function setUp()
    {
        parent::setUp();

        TestAssetStore::activate('VersionedFilesMigrationTest/assets');
        $dest = TestAssetStore::base_path();
        Filesystem::makeFolder(Path::join($dest, 'folder3'));
        Filesystem::makeFolder(Path::join($dest, 'folder2', 'subfolder2'));

        foreach ($this->getTestVersionDirectories() as $path) {
            Filesystem::makeFolder($path);
        }
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
    }

    /**
     * Test delete migration
     */
    public function testMigrationDeletes()
    {
        $migrator = VersionedFilesMigrator::create(
            VersionedFilesMigrator::STRATEGY_DELETE,
            TestAssetStore::base_path(),
            false
        );
        $migrator->migrate();

        foreach ($this->getTestVersionDirectories() as $dir) {
            $path = Path::join(BASE_PATH, $dir);
            $this->assertFalse(is_dir($path), $dir . ' still exists!');
        }
    }

    /**
     * Test protect migration
     */
    public function testMigrationProtects()
    {
        $migrator = VersionedFilesMigrator::create(
            VersionedFilesMigrator::STRATEGY_PROTECT,
            TestAssetStore::base_path(),
            false
        );
        $migrator->migrate();

        foreach ($this->getTestVersionDirectories() as $dir) {
            $path = Path::join($dir, '.htaccess');
            $this->assertTrue(file_exists($path), $path . ' does not exist');
        }
    }

    private function getTestVersionDirectories()
    {
        $base = TestAssetStore::base_path();
        return [
            Path::join($base, 'folder1', '_versions'),
            Path::join($base, 'folder2', '_versions'),
            Path::join($base, 'folder1', 'subfolder1', '_versions')
        ];
    }
}

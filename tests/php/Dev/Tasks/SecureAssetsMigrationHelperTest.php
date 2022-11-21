<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Dev\Tasks\SecureAssetsMigrationHelper;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\Deprecation;

class SecureAssetsMigrationHelperTest extends SapphireTest
{
    protected $usesTransactions = false;

    protected static $fixture_file = 'SecureAssetsMigrationHelperTest.yml';

    /**
     * get the BASE_PATH for this test
     *
     * @return string
     */
    protected function getBasePath()
    {
        // Note that the actual filesystem base is the 'assets' subdirectory within this
        return ASSETS_PATH . '/SecureAssetsMigrationHelperTest';
    }


    protected function setUp(): void
    {
        parent::setUp();

        // Set backend root to /LegacyThumbnailMigrationHelperTest/assets
        TestAssetStore::activate('SecureAssetsMigrationHelperTest/assets');

        // Create all empty folders

        foreach (File::get()->filter('ClassName', Folder::class) as $folder) {
            /** @var $folder Folder */
            $path = TestAssetStore::base_path() . '/' . $folder->generateFilename();
            Filesystem::makeFolder($path);
        }

        // Ensure that each file has a local record file in this new assets base
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            /** @var $file File */
            $file->setFromString('some content', $file->generateFilename());
            $file->write();
            $file->publishFile();
        }
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
    }

    /**
     * @dataProvider dataMigrate
     */
    public function testMigrate($fixture, $htaccess, $expected)
    {
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
        $helper = new SecureAssetsMigrationHelper();

        /** @var TestAssetStore $store */
        $store = singleton(AssetStore::class); // will use TestAssetStore
        $fs = $store->getPublicFilesystem();

        /** @var Folder $folder */
        $folder = $this->objFromFixture(Folder::class, $fixture);
        $path = $folder->getFilename() . '.htaccess';
        $fs->write($path, $htaccess);
        $result = $helper->run($store);

        $this->assertEquals($result, $expected ? [$path] : []);
    }

    public function dataMigrate()
    {
        $htaccess = <<<TXT
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_URI} ^(.*)$
RewriteRule .* framework/main.php?url=%1 [QSA]
TXT;

        $htaccessModified = <<<TXT
modified
TXT;

        return [
            'Protected with valid htaccess' => [
                'protected',
                $htaccess,
                true
            ],
            'Protected nested within unprotected, with valid htaccess' => [
                'protected-sub',
                $htaccess,
                true
            ],
            // .htaccess files on parent folders are respected by Apache
            // See SecureFileExtension->needsAccessFile()
            'Unprotected nested within protected, with valid htaccess' => [
                'protected-inherited',
                $htaccess,
                false
            ],
            'Unprotected' => [
                'unprotected',
                '',
                false
            ],
            'Protected with modified htaccess' => [
                'protected-with-modified-htaccess',
                $htaccessModified,
                false
            ],
        ];
    }

    public function testHtaccessMatchesExact()
    {
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
        $htaccess = <<<TXT
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_URI} ^(.*)$
RewriteRule .* framework/main.php?url=%1 [QSA]
TXT;

        $helper = new SecureAssetsMigrationHelper();
        $this->assertTrue($helper->htaccessMatch($htaccess));
    }

    public function testHtaccessDoesNotMatchWithAdditionsAtStart()
    {
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
        $htaccess = <<<TXT
Other stuff
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_URI} ^(.*)$
RewriteRule .* framework/main.php?url=%1 [QSA]
TXT;

        $helper = new SecureAssetsMigrationHelper();
        $this->assertFalse($helper->htaccessMatch($htaccess));
    }

    public function testHtaccessDoesNotMatchWithAdditionsAtEnd()
    {
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
        $htaccess = <<<TXT
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_URI} ^(.*)$
RewriteRule .* framework/main.php?url=%1 [QSA]

Other stuff
TXT;

        $helper = new SecureAssetsMigrationHelper();
        $this->assertFalse($helper->htaccessMatch($htaccess));
    }

    public function testHtaccessDoesNotMatchWithAdditionsInBetween()
    {
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
        $htaccess = <<<TXT
RewriteEngine On
RewriteBase /
Other stuff
RewriteCond %{REQUEST_URI} ^(.*)$
RewriteRule .* framework/main.php?url=%1 [QSA]
TXT;

        $helper = new SecureAssetsMigrationHelper();
        $this->assertFalse($helper->htaccessMatch($htaccess));
    }
}

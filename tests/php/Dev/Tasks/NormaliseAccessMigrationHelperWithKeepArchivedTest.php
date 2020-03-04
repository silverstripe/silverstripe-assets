<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use InvalidArgumentException;
use LogicException;
use SilverStripe\Assets\AssetControlExtension;
use SilverStripe\Assets\Dev\Tasks\NormaliseAccessMigrationHelper as Helper;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Dev\Tasks\FileMigrationHelper;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
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
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Versioned\Versioned;

/**
 * Ensures that File dataobjects can be safely migrated from 3.x
 */
class NormaliseAccessMigrationHelperWithKeepArchivedTest extends NormaliseAccessMigrationHelperTest
{

    protected $usesTransactions = false;

    protected static $fixture_file = 'NormaliseAccessMigrationHelperTest.yml';

    protected function setUpAssetStore()
    {
        parent::setUpAssetStore();
        File::config()->set('keep_archived_assets', true);
    }

    /**
     * This test is not testing the helper. It is testing that our asset store set up is behaving as expected.
     */
    public function testSanityCheck()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');

        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $publicFs = $store->getPublicFilesystem();
        $protectedFs = $store->getProtectedFilesystem();

        $naturalPath = $file->getFilename();
        $hashPath = sprintf(
            '%s/%s',
            substr($file->getHash(), 0, 10),
            $file->getFilename()
        );

        $this->assertFalse($publicFs->has($naturalPath));
        $this->assertFalse($publicFs->has($hashPath));
        $this->assertFalse($protectedFs->has($naturalPath));
        $this->assertTrue($protectedFs->has($hashPath));

        $file->publishSingle();
        $this->assertTrue($publicFs->has($naturalPath));
        $this->assertFalse($publicFs->has($hashPath));
        $this->assertFalse($protectedFs->has($naturalPath));
        $this->assertFalse($protectedFs->has($hashPath));

        $file->doArchive();
        $this->assertFalse($publicFs->has($naturalPath));
        $this->assertFalse($publicFs->has($hashPath));
        $this->assertFalse($protectedFs->has($naturalPath));
        $this->assertTrue($protectedFs->has($hashPath));
    }
}

<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use InvalidArgumentException;
use LogicException;
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
class NormaliseAccessMigrationHelperWithHashPathTest extends NormaliseAccessMigrationHelperTest
{

    protected $usesTransactions = false;

    protected static $fixture_file = 'NormaliseAccessMigrationHelperTest.yml';

    protected function setUpAssetStore()
    {
        parent::setUpAssetStore();

        /** @var FileIDHelperResolutionStrategy $strategy */
        $strategy = Injector::inst()->get(FileResolutionStrategy::class . '.public');

        $hashHelper = new HashFileIDHelper();
        $strategy->setDefaultFileIDHelper($hashHelper);
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
        $this->assertFalse($publicFs->has($naturalPath));
        $this->assertTrue($publicFs->has($hashPath));
        $this->assertFalse($protectedFs->has($naturalPath));
        $this->assertFalse($protectedFs->has($hashPath));

        $file->doArchive();
        $this->assertFalse($publicFs->has($naturalPath));
        $this->assertFalse($publicFs->has($hashPath));
        $this->assertFalse($protectedFs->has($naturalPath));
        $this->assertFalse($protectedFs->has($hashPath));
    }

    public function testFixWithImageVariants()
    {
        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        $public = $store->getPublicFilesystem();
        $protected = $store->getProtectedFilesystem();
        $variantFilename = 'root__FillWzEwMCwxMDBd.png';

        $img = new Image();
        $img->setFromLocalFile(__DIR__ . '/../../ImageTest/test-image.png', 'root.png');
        $img->write();
        $img->CMSThumbnail()->getURL();

        $hashPath = sprintf('%s/%s', substr($img->getHash(), 0, 10), $variantFilename);

        $this->assertTrue($protected->has($hashPath));

        $img->publishFile();
        $this->assertFalse($protected->has($hashPath));
        $this->assertTrue($public->has($hashPath));

        $helper = Helper::create();
        $helper->fix($img);

        $this->assertTrue($protected->has($hashPath));
        $this->assertFalse($public->has($hashPath));
    }

    public function truncatingFolderDataProvider()
    {
        $hash = substr(sha1('dummy file'), 0, 10);

        return [
            'root files' => [
                ['bad.txt' => true, 'good.txt' => false],
                ["$hash/good.txt"],
                ["$hash/bad.txt"]
            ],
            'bad root files only' => [
                ['bad.txt' => true],
                [],
                ["$hash/bad.txt"],
            ],
            'files in folder' => [
                [
                    'good/good.txt' => false,
                    'good/bad.txt' => true,
                    'bad/bad.txt' => true,
                ],
                ["good/$hash/good.txt"],
                ['bad', "good/$hash/bad.txt"],
            ],
            'bad files in subfolder' => [
                [
                    'dir/good.txt' => false,
                    'dir/bad/bad.txt' => true,
                ],
                ["dir/$hash/good.txt"],
                ['dir/bad'],
            ],
            'good files in subfolder' => [
                [
                    'folder/bad.txt' => true,
                    'folder/good/good.txt' => false,
                ],
                ["folder/good/$hash/good.txt"],
                ["folder/$hash/bad.txt"],
            ],
            'bad files inside deep folder' => [
                ['deeply/bad/file.txt' => true],
                [],
                ['deeply'],
            ],
            'bad files in subfolder with bad file in parent' => [
                [
                    'dir/good.txt' => false,
                    'dir/bad.txt' => true,
                    'dir/bad/bad.txt' => true,
                ],
                ["dir/$hash/good.txt"],
                ['dir/bad', "dir/$hash/bad.txt"],
            ],
        ];
    }
}

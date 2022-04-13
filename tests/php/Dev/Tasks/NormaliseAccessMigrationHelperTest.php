<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Assets\Dev\Tasks\NormaliseAccessMigrationHelper as Helper;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Versioned\Versioned;

/**
 * Ensures that File dataobjects can be safely migrated from 3.x
 */
class NormaliseAccessMigrationHelperTest extends SapphireTest
{
    protected $usesTransactions = false;

    protected static $fixture_file = 'NormaliseAccessMigrationHelperTest.yml';

    /**
     * get the BASE_PATH for this test
     *
     * @return string
     */
    protected function getBasePath()
    {
        // Note that the actual filesystem base is the 'assets' subdirectory within this
        return ASSETS_PATH . '/NormaliseAccessMigrationHelperTest';
    }


    protected function setUp(): void
    {
        parent::setUp();

        Injector::inst()->registerService(new NullLogger(), LoggerInterface::class . '.quiet');

        $this->setUpAssetStore();

        /** @var File[] $files */
        $files = File::get()->exclude('ClassName', Folder::class);
        foreach ($files as $file) {
            $file->setFromString($file->getFilename(), $file->getFilename());
            $file->write();
        }
    }

    protected function setUpAssetStore()
    {
        TestAssetStore::activate('NormaliseAccessMigrationHelperTest/assets');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
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
            substr($file->getHash() ?? '', 0, 10),
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
        $this->assertFalse($protectedFs->has($hashPath));
    }

    private function getHelper()
    {
        return new Helper('/assets/NormaliseAccessMigrationHelperTest/');
    }

    public function testNeedToMoveWithFolder()
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @var File $file */
        $folder = Folder::find('Uploads');
        $helper = $this->getHelper();
        $helper->needToMove($folder);
    }

    public function testNeedToMovePublishedNoRestrictionFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->publishSingle();
        $file->publishFile();

        $helper = $this->getHelper();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'Published non-retricted file on public store does not require any moving'
        );

        $file->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PUBLIC],
            $action,
            'Published non-retricted file on protected store need to be move to public store'
        );
    }

    public function testNeedToMovePublishedRestrictedFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->publishSingle();
        $file->publishFile();

        $helper = $this->getHelper();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PROTECTED],
            $action,
            'Published retricted file on public store need to be move to protected store'
        );

        $file->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'Published retricted file on protected store does not require any moving'
        );
    }

    public function testNeedToMoveMetaChangedOnlyNoRestrictionFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->publishSingle();

        $file->Title = 'Changing the title should not affect which store the file is stored on';
        $file->write();

        $file->publishFile();

        $helper = $this->getHelper();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'Published non-retricted file on public store with metadata draft change does not need moving'
        );

        $file->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PUBLIC],
            $action,
            'Published non-retricted file on protected store with metadata draft change need to be move to public'
        );
    }

    public function testNeedToMoveMetaChangedOnlyRestrictedFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->publishSingle();

        $file->Title = 'Changing the title should not affect which store the file is stored on';
        $file->write();

        $file->publishFile();

        $helper = $this->getHelper();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PROTECTED],
            $action,
            'Published retricted file on public store with draft metadata changes need to move to protected store'
        );

        $file->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'Published retricted file on protected store with draft metadata changes does not need moving'
        );
    }

    public function testNeedToMoveDraftNoRestrictionFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->doUnpublish();
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->write();
        $file->publishFile();

        $helper = $this->getHelper();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
            $action,
            'draft non-restricted file on public store need to move to protected store'
        );

        $file->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'draft non-restricted file on protected store do not need to be moved'
        );
    }

    public function testNeedToMoveDraftRestrictedFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->doUnpublish();
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->write();
        $file->publishFile();

        $helper = $this->getHelper();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
            $action,
            'Draft restricted file on public store need to be moved to protected strore'
        );

        $file->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'Draft restricted file on public store don\'t need to move'
        );
    }

    public function testNeedToMoveUnrestrictedMixedDraftLiveFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->publishSingle();

        $file->setFromString('Draf file 1', $file->getFilename());
        $file->write();
        /** @var File $liveFile */
        $liveFile = Versioned::get_by_stage($file->ClassName, Versioned::LIVE)->byID($file->ID);

        $helper = $this->getHelper();

        $file->protectFile();
        $liveFile->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PUBLIC],
            $action,
            'Non-restricted published file on protected store with draft on protected store need to move to public'
        );

        $liveFile->publishFile();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'Non-restricted published file on public store with draft on protected store do not need to move'
        );

        $liveFile->protectFile();
        $file->publishFile();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PUBLIC, Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
            $action,
            'Non-restricted published file on protected store with draft on public store need to move ' .
            'live file for public and draft file to protected'
        );
    }

    public function testNeedToMoveRestrictedMixedDraftLiveFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->publishSingle();

        $file->setFromString('Draf file 1', $file->getFilename());
        $file->write();
        /** @var File $liveFile */
        $liveFile = Versioned::get_by_stage($file->ClassName, Versioned::LIVE)->byID($file->ID);

        $helper = $this->getHelper();

        $file->protectFile();
        $liveFile->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'Restricted published file on protected store with draft on protected store do not need to move'
        );

        $liveFile->publishFile();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PROTECTED],
            $action,
            'Restricted published file on public store with draft on protected store need to move to protected'
        );

        $liveFile->protectFile();
        $file->publishFile();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
            $action,
            'Restricted published file on protected store with draft on public store need to move draft to protected'
        );
    }

    public function testNeedToMoveRestrictedLiveFileUnrestrictedDraft()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->publishSingle();
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->write();

        $helper = $this->getHelper();

        $file->protectFile();
        $action = $helper->needToMove($file);
        $this->assertEmpty(
            $action,
            'Restricted published file on protected store with unrestricted draft do not need to be moved'
        );

        $file->publishFile();
        $action = $helper->needToMove($file);
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PROTECTED],
            $action,
            'Restricted published file on public store with unrestricted draft need to move to protected store'
        );
    }

    /**
     * @note Live files get their permissions from the draft file. This is probably a bug, but we'll have to
     * look at it later.
     */
    public function testNeedToMoveUnrestrictedLiveFileRestrictedDraft()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->publishSingle();
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->write();

        $helper = $this->getHelper();

        $file->protectFile();
        $this->assertEmpty(
            $helper->needToMove($file),
            'Unrestricted published file on protected store with restricted draft do not need to move'
        );

        $file->publishFile();
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PROTECTED],
            $helper->needToMove($file),
            'Unrestricted published file on public store with restricted draft need to move to protected store'
        );
    }

    /**
     * @note Live files get their permissions from the draft file. This is probably a bug, but we'll have to
     * look at it later.
     */
    public function testNeedToMoveRestrictedLiveUnrestrictedDraft()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->publishSingle();
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->write();

        $helper = $this->getHelper();

        $file->protectFile();
        $this->assertEmpty(
            $helper->needToMove($file),
            'Restricted published file on protected store with unrestricted draft do not need to move'
        );

        $file->publishFile();
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PROTECTED],
            $helper->needToMove($file),
            'Restricted published file on public store with unrestricted draft need to move to protected store'
        );
    }

    public function testNeedToMoveFileInProtectedFolder()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'secret');
        $file->doUnpublish();

        $helper = $this->getHelper();

        $file->protectFile();
        $this->assertEmpty(
            $helper->needToMove($file),
            'Folder-restricted draft file on protected store do not need to move'
        );

        $file->publishFile();
        $this->assertEquals(
            [Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
            $helper->needToMove($file),
            'Folder-restricted draft file on public store need to move to protected'
        );

        $file->publishSingle();
        $file->protectFile();
        $this->assertEmpty(
            $helper->needToMove($file),
            'Folder-restricted live file on protected store do not need to move'
        );

        $file->publishFile();
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PROTECTED],
            $helper->needToMove($file),
            'Folder-restricted live file on public store need to move to protected store'
        );
    }

    public function testNeedToMoveRenamedFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->publishSingle();
        $file->setFilename('updated-file-name.txt');
        $file->write();
        $helper = $this->getHelper();

        $file->publishFile();
        $this->assertEmpty(
            $helper->needToMove($file),
            'Live file on public store with rename draft file does not need to be move'
        );

        $file->protectFile();
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PUBLIC],
            $helper->needToMove($file),
            'Live file on protected store with rename draft file does need to be moved to public'
        );
    }

    public function testNeedToMoveReplacedRenamedFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::ANYONE;
        $file->publishSingle();
        $liveVersion = $file->Version;
        $file->setFromString('New file with new name', 'updated-file-name.txt');
        $file->write();

        /** @var File $liveFile */
        $liveFile = Versioned::get_version(File::class, $file->ID, $liveVersion);

        $helper = $this->getHelper();

        $file->protectFile();
        $liveFile->publishFile();
        $this->assertEmpty(
            $helper->needToMove($file),
            'Live file on public store with renamed-replaced draft on protected store ' .
            'do not need to move'
        );

        $liveFile->protectFile();
        $file->publishFile();
        $this->assertEquals(
            [Versioned::LIVE => AssetStore::VISIBILITY_PUBLIC, Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
            $helper->needToMove($file),
            'Live file on protected store with renamed-replaced draft on public store ' .
            'need to be life file to public and draft file to protected'
        );
    }

    public function testFindBadFilesWithProtectedDraft()
    {
        /** @var File $file */
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $file->protectFile();
        }
        $helper = $this->getHelper();
        $this->assertEmpty(
            $helper->findBadFiles(),
            'All files are in draft and protected. There\'s nothing to do'
        );
    }

    public function testFindBadFilesWithPublishedDraft()
    {
        /** @var File $file */
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $file->publishFile();
        }
        $helper = $this->getHelper();
        $this->assertEquals(
            [
                $this->idFromFixture(File::class, 'file1') =>
                    [Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
                $this->idFromFixture(File::class, 'secret') =>
                    [Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
            ],
            $helper->findBadFiles(),
            'All files are in draft and public. All of files need to be moved to the protected store'
        );
    }

    public function testFindBadFilesWithProtectedLive()
    {
        /** @var File $file */
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $file->publishSingle();
            $file->protectFile();
        }
        $helper = $this->getHelper();
        $this->assertEmpty(
            $helper->findBadFiles(),
            'All files are published and protected. file1 should be public, ' .
            'but the helper doesn\'t publish wrongly protected file'
        );
    }

    public function testFindBadFilesWithPublishedLive()
    {
        /** @var File $file */
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $file->publishSingle();
            $file->publishFile();
        }
        $helper = $this->getHelper();

        $this->assertEquals(
            [
                $this->idFromFixture(File::class, 'secret') =>
                    [Versioned::LIVE => AssetStore::VISIBILITY_PROTECTED],
            ],
            $helper->findBadFiles(),
            'All files are published and published. secret is mark as bad because it should be protected'
        );
    }

    public function testFindBadFilesWithMultiPublishedVersions()
    {
        /** @var File $file */
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $file->publishSingle();
            $liveFile = Versioned::get_version(File::class, $file->ID, $file->Version);
            $file->protectFile();
            $file->setFromString($file->getFilename() . ' draft content', $file->getFilename());
            $file->write();
            $liveFile->protectFile();
            $file->publishFile();
        }
        $helper = $this->getHelper();

        $this->assertEquals(
            [
                $this->idFromFixture(File::class, 'file1') =>
                    [
                        Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED
                    ],
                $this->idFromFixture(File::class, 'secret') =>
                    [Versioned::DRAFT => AssetStore::VISIBILITY_PROTECTED],
            ],
            $helper->findBadFiles(),
            'When files have different draft version, the draft and live version should be check individually'
        );
    }

    public function testNeedToMoveNonExistentFile()
    {
        $this->expectException(\LogicException::class);
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->publishSingle();
        $file->deleteFile();

        $helper = $this->getHelper();

        $action = $helper->needToMove($file);
    }

    public function testRun()
    {
        /** @var File $file */
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $file->publishFile();
        }
        $helper = $this->getHelper();

        $this->assertEquals(
            [
                'total' => 2,
                'success' => 2,
                'fail' => 0
            ],
            $helper->run(),
            '2 files need to be protected'
        );

        $this->assertEquals(
            [
                'total' => 0,
                'success' => 0,
                'fail' => 0
            ],
            $helper->run(),
            'All files have already been protected'
        );
    }

    /**
     * The point of this test is to make sure the chunking logic works as expected
     */
    public function testRunWithLotsOfFiles()
    {
        // Create a two hundred good files
        for ($i = 0; $i < 200; $i++) {
            $file = new File();
            $file->setFromString("good file $i", "good$i.txt");
            $file->write();
        }

        // Create a two hundred bad files with draft in the public store
        for ($i = 0; $i < 201; $i++) {
            $file = new File();
            $file->setFromString("bad file $i", "bad$i.txt");
            $file->write();
            $file->publishFile();
        }

        // Create a two hundred fail files that will throw warning
        for ($i = 0; $i < 202; $i++) {
            $file = new File();
            $file->setFromString("fail file $i", "fail$i.txt");
            $file->write();
            $file->deleteFile();
        }

        $helper = $this->getHelper();

        $this->assertEquals(
            [
                'total' => 201,
                'success' => 201,
                'fail' => 202
            ],
            $helper->run(),
            'When looping over a list of files greater than limit, all files should be prcessed'
        );

        $this->assertEquals(
            [
                'total' => 0,
                'success' => 0,
                'fail' => 202
            ],
            $helper->run(),
            'When running the helper twice in a row, all files that can be fix have already been fixed.'
        );
    }

    public function testFixWithProtectedDraftFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->protectFile();

        $helper = $this->getHelper();
        $helper->fix($file);
        $this->assertVisibility(
            AssetStore::VISIBILITY_PROTECTED,
            $file,
            'Protected draft file is protected after fix'
        );
    }

    public function testFixWithPublicDraftFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->publishFile();

        $helper = $this->getHelper();
        $helper->fix($file);
        $this->assertVisibility(
            AssetStore::VISIBILITY_PROTECTED,
            $file,
            'Public draft file is protected after fix'
        );
    }

    public function testFixWithPublicLiveFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->publishSingle();
        $file->publishFile();

        $helper = $this->getHelper();
        $helper->fix($file);
        $this->assertVisibility(
            AssetStore::VISIBILITY_PUBLIC,
            $file,
            'Public live file is public after fix'
        );
    }

    public function testFixWithProtectedLiveFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->publishSingle();
        $file->protectFile();

        $helper = $this->getHelper();
        $helper->fix($file);
        $this->assertVisibility(
            AssetStore::VISIBILITY_PUBLIC,
            $file,
            'Protected live file is public after fix'
        );
    }

    public function testFixWithPublicRestrictedLiveFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->publishSingle();
        $file->publishFile();

        $helper = $this->getHelper();
        $helper->fix($file);
        $this->assertVisibility(
            AssetStore::VISIBILITY_PROTECTED,
            $file,
            'Public restricted live file is protected after fix'
        );
    }

    public function testFixWithProtectedRestrictedLiveFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $file->publishSingle();
        $file->protectFile();

        $helper = $this->getHelper();
        $helper->fix($file);
        $this->assertVisibility(
            AssetStore::VISIBILITY_PROTECTED,
            $file,
            'Protected restricted live file is protected after fix'
        );
    }

    public function testFixWithUpdatedDraftMetadataFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->publishSingle();
        $liveVersionID = $file->Version;
        $file->Title = 'new title';
        $file->write();

        $helper = $this->getHelper();
        $helper->fix($file);
        $this->assertVisibility(
            AssetStore::VISIBILITY_PUBLIC,
            $file,
            'Draft meta data changes do not affect visibility of live file'
        );

        $this->assertVisibility(
            AssetStore::VISIBILITY_PUBLIC,
            Versioned::get_version(File::class, $file->ID, $liveVersionID),
            'Live file is still public even if there\'s a draft metadata change'
        );
    }

    public function testFixWithUpdatedDraftFile()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $file->publishSingle();
        $liveVersionID = $file->Version;

        $file->setFromString('Updated content', 'newfile1.txt');
        $file->write();

        /** @var File $liveFile */
        $liveFile = Versioned::get_version(File::class, $file->ID, $liveVersionID);
        $liveFile->protectFile();
        $file->publishFile();

        $helper = $this->getHelper();
        $helper->fix($file);
        $this->assertVisibility(
            AssetStore::VISIBILITY_PROTECTED,
            $file,
            ''
        );

        $this->assertVisibility(
            AssetStore::VISIBILITY_PUBLIC,
            $liveFile,
            ''
        );
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

        $hashPath = sprintf('%s/%s', substr($img->getHash() ?? '', 0, 10), $variantFilename);

        $this->assertTrue($protected->has($hashPath));

        $img->publishFile();
        $this->assertFalse($protected->has($hashPath));
        $this->assertTrue($public->has($variantFilename));

        $helper = $this->getHelper();
        $helper->fix($img);

        $this->assertTrue($protected->has($hashPath));
        $this->assertFalse($public->has($variantFilename));
    }

    private function assertVisibility($expected, File $file, $message = '')
    {
        $this->assertEquals($expected, $file->getVisibility(), $message);
    }


    public function truncatingFolderDataProvider()
    {
        return [
            'root files' => [
                ['bad.txt' => true, 'good.txt' => false],
                ['good.txt'],
                ['bad.txt']
            ],
            'bad root files only' => [
                ['bad.txt' => true],
                [],
                ['bad.txt'],
            ],
            'files in folder' => [
                [
                    'good/good.txt' => false,
                    'good/bad.txt' => true,
                    'bad/bad.txt' => true,
                ],
                ['good/good.txt'],
                ['bad', 'good/bad.txt'],
            ],
            'bad files in subfolder' => [
                [
                    'dir/good.txt' => false,
                    'dir/bad/bad.txt' => true,
                ],
                ['dir/good.txt'],
                ['dir/bad'],
            ],
            'good files in subfolder' => [
                [
                    'folder/bad.txt' => true,
                    'folder/good/good.txt' => false,
                ],
                ['folder/good/good.txt'],
                ['folder/bad.txt'],
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
                ['dir/good.txt'],
                ['dir/bad', 'dir/bad.txt'],
            ],
        ];
    }

    /**
     * @dataProvider truncatingFolderDataProvider
     */
    public function testFolderTruncating($filePaths, $expected, $unexpected)
    {
        // Removing existing fixtures
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $file->delete();
        }

        // Create a folder structure
        foreach ($filePaths as $filePath => $broken) {
            $file = new File();
            $file->setFromString('dummy file', $filePath);
            $file->write();
            if (!$broken) {
                $file->publishSingle();
            }
            $file->publishFile();
        }

        /** @var \League\Flysystem\Filesystem $fs */
        $fs = Injector::inst()->get(AssetStore::class)->getPublicFilesystem();

        // Assert that the expect files exists prior to the task running
        foreach ($expected as $expectedPath) {
            $this->assertTrue($fs->has($expectedPath), sprintf('%s exists', $expectedPath));
        }
        // Assert that the unexpect files exists prior to the task running
        foreach ($unexpected as $unexpectedPath) {
            $this->assertTrue($fs->has($unexpectedPath), sprintf('%s does not exist', $unexpectedPath));
        }

        // Fix broken file
        $helper = $this->getHelper();
        $helper->run();

        // Assert existence of file/folder
        foreach ($expected as $expectedPath) {
            $this->assertTrue($fs->has($expectedPath), sprintf('%s exists', $expectedPath));
        }
        // Assert non-existence of file/folder
        foreach ($unexpected as $unexpectedPath) {
            $this->assertFalse($fs->has($unexpectedPath), sprintf('%s does not exist', $unexpectedPath));
        }
    }
}

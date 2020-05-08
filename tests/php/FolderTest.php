<?php

namespace SilverStripe\Assets\Tests;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\FolderNameFilter;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * @author Ingo Schommer (ingo at silverstripe dot com)
 * @skipUpgrade
 */
class FolderTest extends SapphireTest
{

    protected static $fixture_file = 'FileTest.yml';

    public function setUp()
    {
        parent::setUp();

        $this->logInWithPermission('ADMIN');
        Versioned::set_stage(Versioned::DRAFT);

        // Set backend root to /FolderTest
        TestAssetStore::activate('FolderTest');

        // Set the File Name Filter replacements so files have the expected names
        Config::modify()->set(
            FolderNameFilter::class,
            'default_replacements',
            [
                '/\s/' => '-', // remove whitespace
                '/_/' => '-', // underscores to dashes
                '/[^A-Za-z0-9+.\-]+/' => '', // remove non-ASCII chars, only allow alphanumeric plus dash and dot
                '/[\-]{2,}/' => '-', // remove duplicate dashes
                '/^[\.\-_]+/' => '', // Remove all leading dots, dashes or underscores,
                '/\./' => '-', // replace dots with dashes
            ]
        );

        // Create a test files for each of the fixture references
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            /** @var File $file */

            $file->File->Hash = sha1('version 1');
            $file->write();

            // Create variant for each file
            $file->setFromString(
                'version 1',
                $file->getFilename(),
                $file->getHash(),
                null,
                ['visibility' => AssetStore::VISIBILITY_PROTECTED]
            );
        }
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testCreateFromNameAndParentIDSetsFilename()
    {
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');
        $newFolder = new Folder();
        $newFolder->Name = 'CreateFromNameAndParentID';
        $newFolder->ParentID = $folder1->ID;
        $newFolder->write();

        $this->assertEquals($folder1->Filename . 'CreateFromNameAndParentID/', $newFolder->Filename);
    }

    public function testRenamesDuplicateFolders()
    {
        $original = new Folder();
        $original->update([
            'Name' => 'folder1',
            'ParentID' => 0
        ]);
        $original->write();

        $duplicate = new Folder();
        $duplicate->update([
            'Name' => 'folder1',
            'ParentID' => 0
        ]);
        $duplicate->write();

        $original = Folder::get()->byID($original->ID);

        $this->assertEquals($original->Name, 'folder1');
        $this->assertEquals($original->Title, 'folder1');
        $this->assertEquals($duplicate->Name, 'folder1-v2');
        $this->assertEquals($duplicate->Title, 'folder1-v2');
    }

    public function testAllChildrenIncludesFolders()
    {
        /** @var Folder $folder1 */
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');
        $subfolder1 = $this->objFromFixture(Folder::class, 'folder1-subfolder1');
        $file1 = $this->objFromFixture(File::class, 'file1-folder1');

        $children = $folder1->allChildren();
        $this->assertEquals(2, $children->Count());
        $this->assertContains($subfolder1->ID, $children->column('ID'));
        $this->assertContains($file1->ID, $children->column('ID'));
    }

    public function testFindOrMake()
    {
        $path = 'parent/testFindOrMake/';
        $folder = Folder::find_or_make($path);
        $this->assertEquals(
            ASSETS_PATH . '/FolderTest/' . $path,
            TestAssetStore::getLocalPath($folder),
            'Nested path information is correctly saved to database (with trailing slash)'
        );

        // Folder does not exist until it contains files
        $this->assertFileNotExists(
            TestAssetStore::getLocalPath($folder),
            'Empty folder does not have a filesystem record automatically'
        );

        $parentFolder = DataObject::get_one(
            Folder::class,
            array(
            '"File"."Name"' => 'parent'
            )
        );
        $this->assertNotNull($parentFolder);
        $this->assertEquals($parentFolder->ID, $folder->ParentID);

        $path = 'parent/testFindOrMake'; // no trailing slash
        $folder = Folder::find_or_make($path);
        $this->assertEquals(
            ASSETS_PATH . '/FolderTest/' . $path . '/', // Slash is automatically added here
            TestAssetStore::getLocalPath($folder),
            'Path information is correctly saved to database (without trailing slash)'
        );

        $path = 'assets/'; // relative to "assets/" folder, should produce "assets/assets/"
        $folder = Folder::find_or_make($path);
        $this->assertEquals(
            ASSETS_PATH . '/FolderTest/' . $path,
            TestAssetStore::getLocalPath($folder),
            'A folder named "assets/" within "assets/" is allowed'
        );
    }

    /**
     * Tests for the bug #5994 - Moving folder after executing Folder::findOrMake will not set the Filenames properly
     */
    public function testFindOrMakeFolderThenMove()
    {
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');
        Folder::find_or_make($folder1->Filename);
        $folder2 = $this->objFromFixture(Folder::class, 'folder2');

        // Publish file1
        /** @var File $file1 */
        $file1 = DataObject::get_by_id(File::class, $this->idFromFixture(File::class, 'file1-folder1'), false);
        $file1->publishRecursive();

        // set ParentID. This should cause updateFilesystem to be called on all children
        $folder1->ParentID = $folder2->ID;
        $folder1->write();

        // Check if the file in the folder moved along
        /** @var File $file1Draft */
        $file1Draft = Versioned::get_by_stage(File::class, Versioned::DRAFT)->byID($file1->ID);
        $this->assertFileExists(ASSETS_PATH . '/FolderTest/.protected/FileTest-folder2/FileTest-folder1/58a74a7aa4/File1.txt');

        $this->assertEquals(
            'FileTest-folder2/FileTest-folder1/File1.txt',
            $file1Draft->Filename,
            'The file DataObject has updated path'
        );

        // File should be located in new folder
        $this->assertEquals(
            ASSETS_PATH . '/FolderTest/.protected/FileTest-folder2/FileTest-folder1/58a74a7aa4/File1.txt',
            TestAssetStore::getLocalPath($file1Draft)
        );

        // Published (live) version remains in the old location
        /** @var File $file1Live */
        $file1Live = Versioned::get_by_stage(File::class, Versioned::LIVE)->byID($file1->ID);
        $this->assertEquals(
            ASSETS_PATH . '/FolderTest/FileTest-folder1/File1.txt',
            TestAssetStore::getLocalPath($file1Live)
        );

        // Publishing the draft to live should move the new file to the public store
        $file1Draft->publishRecursive();
        $this->assertEquals(
            ASSETS_PATH . '/FolderTest/FileTest-folder2/FileTest-folder1/File1.txt',
            TestAssetStore::getLocalPath($file1Draft)
        );
    }

    public function testFindOrMakeDisallowsDotsInFolderNames()
    {
        $folder = Folder::find_or_make('my.folder');
        $this->assertSame('my-folder', $folder->Name);
    }

    public function testSetNameDisallowsDotsInFolderNames()
    {
        $folder = new Folder();
        $folder->Name = 'another.one';
        $folder->write();

        $this->assertSame('another-one', $folder->Name);
    }

    /**
     * Tests for the bug #5994 - if you don't execute get_by_id prior to the rename or move, it will fail.
     */
    public function testRenameFolderAndCheckTheFile()
    {
        // ID is prefixed in case Folder is subclassed by project/other module.
        $folder1 = DataObject::get_one(
            Folder::class,
            array(
            '"File"."ID"' => $this->idFromFixture(Folder::class, 'folder1')
            )
        );

        $folder1->Name = 'FileTest-folder1-changed';
        $folder1->write();

        // Check if the file in the folder moved along
        /** @var File $file1 */
        $file1 = DataObject::get_by_id(File::class, $this->idFromFixture(File::class, 'file1-folder1'), false);
        $this->assertFileExists(
            TestAssetStore::getLocalPath($file1)
        );
        $this->assertEquals(
            $file1->Filename,
            'FileTest-folder1-changed/File1.txt',
            'The file DataObject path uses renamed folder'
        );

        // File should be located in new folder
        $this->assertEquals(
            ASSETS_PATH . '/FolderTest/.protected/FileTest-folder1-changed/58a74a7aa4/File1.txt',
            TestAssetStore::getLocalPath($file1)
        );
    }

    /**
     * URL and Link are undefined for folder dataobjects
     */
    public function testLinkAndRelativeLink()
    {
        /** @var Folder $folder */
        $folder = $this->objFromFixture(Folder::class, 'folder1');
        $this->assertEmpty($folder->getURL());
        $this->assertEmpty($folder->Link());
    }

    public function testIllegalFilenames()
    {

        // Test that generating a filename with invalid characters generates a correctly named folder.
        $folder = Folder::find_or_make('/FolderTest/EN_US Lang');
        $this->assertEquals('FolderTest/EN-US-Lang/', $folder->getFilename());

        // Test repeatitions of folder
        $folder2 = Folder::find_or_make('/FolderTest/EN_US Lang');
        $this->assertEquals($folder->ID, $folder2->ID);

        $folder3 = Folder::find_or_make('/FolderTest/EN--US_L!ang');
        $this->assertEquals($folder->ID, $folder3->ID);

        $folder4 = Folder::find_or_make('/FolderTest/EN-US-Lang');
        $this->assertEquals($folder->ID, $folder4->ID);
    }

    public function testTitleTiedToName()
    {
        $newFolder = new Folder();

        $newFolder->Name = 'TestNameCopiedToTitle';
        $this->assertEquals($newFolder->Name, $newFolder->Title);
        $this->assertEquals($newFolder->Title, 'TestNameCopiedToTitle');

        $newFolder->Title = 'TestTitleCopiedToName';
        $this->assertEquals($newFolder->Name, $newFolder->Title);
        $this->assertEquals($newFolder->Title, 'TestTitleCopiedToName');

        $newFolder->Name = 'TestNameWithIllegalCharactersCopiedToTitle <!BANG!>';
        $this->assertEquals($newFolder->Name, $newFolder->Title);
        $this->assertEquals($newFolder->Title, 'TestNameWithIllegalCharactersCopiedToTitle <!BANG!>');

        $newFolder->Title = 'TestTitleWithIllegalCharactersCopiedToName <!BANG!>';
        $this->assertEquals($newFolder->Name, $newFolder->Title);
        $this->assertEquals($newFolder->Title, 'TestTitleWithIllegalCharactersCopiedToName <!BANG!>');

        // Title should be populated from name on first write
        $writeFolder = new Folder();
        $writeFolder->Name = 'TestNameWrittenToTitle';
        $writeFolder->write();
        $newFolderWritten = Folder::get_one(Folder::class, "\"Title\" = 'TestNameWrittenToTitle'");
        $this->assertNotNull($newFolderWritten);

        // Title should be populated from name on subsequent writes
        $writeFolder->Name = 'TestNameWrittenToTitleOnUpdate';
        $writeFolder->write();
        $newFolderWritten = Folder::get_one(Folder::class, "\"Title\" = 'TestNameWrittenToTitleOnUpdate'");
        $this->assertNotNull($newFolderWritten);
    }

    public function testRootFolder()
    {
        $root = Folder::singleton();
        $this->assertEquals('/', $root->getFilename());
    }

    /**
     * Test permissions on folders recurse to children.
     * Note: draft permissions
     */
    public function testPermissions()
    {
        $member = $this->objFromFixture(Member::class, 'assetadmin');

        // Ensure all folders / files are published
        /** @var Folder $restrictedFolderDraft */
        $restrictedFolderDraft = $this->objFromFixture(Folder::class, 'restrictedViewFolder');
        $restrictedFolderDraft->forceChange();
        $restrictedFolderDraft->write();
        $restrictedFolderLive = Versioned::get_by_stage(Folder::class, Versioned::LIVE)
            ->byID($restrictedFolderDraft->ID);

        /** @var File $restrictedFileDraft */
        $restrictedFileDraft = $this->objFromFixture(File::class, 'restrictedViewFolder-file4');
        $restrictedFileDraft->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $restrictedFileLive = Versioned::get_by_stage(File::class, Versioned::LIVE)
            ->byID($restrictedFileDraft->ID);

        // Only member can view these files
        $this->logInAs($member);
        $this->assertTrue($restrictedFileLive->canView());
        $this->assertTrue($restrictedFolderLive->canView());
        $this->logOut();
        $this->assertFalse($restrictedFileLive->canView());
        $this->assertFalse($restrictedFolderLive->canView());

        // Folder can be made public
        $restrictedFolderDraft->CanViewType = InheritedPermissions::ANYONE;
        $restrictedFolderDraft->write();
        $this->assertFalse($restrictedFolderLive->canView());
        $this->assertTrue($restrictedFileLive->canView());
        $restrictedFolderDraft->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $restrictedFolderLive = Versioned::get_by_stage(Folder::class, Versioned::LIVE)
            ->byID($restrictedFolderDraft->ID);
        $this->assertTrue($restrictedFolderLive->canView());
        $this->assertTrue($restrictedFileLive->canView());

        // Test that a public folder can be made protected
        /** @var Folder $publicFolderDraft */
        $publicFolderDraft = $this->objFromFixture(Folder::class, 'folder1');
        $publicFolderDraft->forceChange();
        $publicFolderDraft->write();
        $publicFolderLive = Versioned::get_by_stage(Folder::class, Versioned::LIVE)
            ->byID($publicFolderDraft->ID);

        /** @var File $publicFileDraft */
        $publicFileDraft = $this->objFromFixture(File::class, 'file1-folder1');
        $publicFileDraft->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $publicFileLive = Versioned::get_by_stage(File::class, Versioned::LIVE)
            ->byID($publicFileDraft->ID);

        // Anyone can view these files
        $this->assertTrue($publicFileLive->canView());
        $this->assertTrue($publicFolderLive->canView());

        // Folder can be made protected
        $publicFolderDraft->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $publicFolderDraft->write();
        $this->assertFalse($publicFileLive->canView());
        $this->assertFalse($publicFolderLive->canView());
        $this->logInAs($member);
        $this->assertTrue($publicFileLive->canView());
        $this->assertTrue($publicFolderLive->canView());
    }

    /**
     * Ensure that child records ensure parent folders are published
     */
    public function testChildrenEnsureParentsPublish()
    {
        $folder1 = Folder::create();
        $folder1->Name = 'ParentFolder';
        $folder1->write();
        $folder2 = Folder::create();
        $folder2->Name = 'SubFolder';
        $folder2->ParentID = $folder1->ID;
        $folder2->write();

        // Should auto-assign to the above folders
        $file = File::create();
        $file->setFromString('file content', 'ParentFolder/SubFolder/myfile.txt');
        $file->write();
        $this->assertTrue($file->isOnDraftOnly());
        $this->assertTrue($file->exists());
        $this->assertEquals($folder2->ID, $file->ParentID);
        $this->assertEquals('ParentFolder/SubFolder/myfile.txt', $file->getFilename());

        // Publish should recurse upwards (or already be recursed)
        $file->publishSingle();
        $this->assertTrue($file->isPublished());
        $this->assertTrue($folder1->isPublished());
        $this->assertTrue($folder2->isPublished());
    }

    /**
     * When in archived mode, find or make should not find folders that don't exist
     */
    public function testFindOrMakeVersioned()
    {
        // Create a folder
        Folder::find_or_make('Folder-that-does-exist');

        Versioned::withVersionedMode(function () {
            Versioned::set_reading_mode('Archive.2000-01-01 12:00:00.Live');
            $folder = Folder::find_or_make('Folder-that-does-not-exist');
            // Since this folder doesn't exist we would expect the result to be null
            $this->assertEmpty($folder);

            // Since this folder doesn't  exist at that date and time we would expect the result to be null
            $folder = Folder::find_or_make('Folder-that-does-exist');
            $this->assertEmpty($folder);

            // Hopefully in 3000 years we've found a better date setting
            Versioned::set_reading_mode('Archive.3000-01-01 12:00:00.Live');
            $folder = Folder::find_or_make('Folder-that-does-exist');
            $this->assertNotNull($folder);
        });
    }
}

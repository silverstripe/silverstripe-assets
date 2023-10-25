<?php

namespace SilverStripe\Assets\Tests;

use Generator;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\MockObject\MockObject;
use SilverStripe\Assets\AssetControlExtension;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Tests\FileTest\MyCustomFile;
use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\Member;
use SilverStripe\Security\PermissionChecker;
use SilverStripe\Versioned\Versioned;

/**
 * Tests for the File class
 */
class FileTest extends SapphireTest
{

    protected static $fixture_file = 'FileTest.yml';

    protected static $extra_dataobjects = [
        MyCustomFile::class
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
        Versioned::set_stage(Versioned::DRAFT);

        // Set backend root to /ImageTest
        TestAssetStore::activate('FileTest');

        // Create a test files for each of the fixture references
        $fileIDs = array_merge(
            $this->allFixtureIDs(File::class),
            $this->allFixtureIDs(Image::class)
        );
        foreach ($fileIDs as $fileID) {
            /** @var File $file */
            $file = DataObject::get_by_id(File::class, $fileID);
            $file->setFromString(str_repeat('x', 1000000), $file->getFilename());
        }

        // Conditional fixture creation in case the 'cms' module is installed
        if (class_exists(ErrorPage::class)) {
            $page = new ErrorPage(
                [
                'Title' => 'Page not Found',
                'ErrorCode' => 404
                ]
            );
            $page->write();
            $page->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        }
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testCreateWithFilenameWithSubfolder()
    {
        // Note: We can't use fixtures/setUp() for this, as we want to create the db record manually.
        // Creating the folder is necessary to avoid having "Filename" overwritten by setName()/setRelativePath(),
        // because the parent folders don't exist in the database
        $folder = Folder::find_or_make('/FileTest/');
        $testfilePath = ASSETS_PATH . '/FileTest/CreateWithFilenameHasCorrectPath.txt'; // Important: No leading slash
        $fh = fopen($testfilePath ?? '', 'w');
        fwrite($fh, str_repeat('x', 1000000));
        fclose($fh);

        $file = new File();

        $file->File->Hash = sha1_file($testfilePath ?? '');
        $file->setFromLocalFile($testfilePath);
        $file->ParentID = $folder->ID;
        $file->write();

        $this->assertEquals(
            'CreateWithFilenameHasCorrectPath.txt',
            $file->Name,
            '"Name" property is automatically set from "Filename"'
        );
        $this->assertEquals(
            'FileTest/CreateWithFilenameHasCorrectPath.txt',
            $file->getFilename(),
            '"Filename" property remains unchanged'
        );

        // TODO This should be auto-detected, see File->updateFilesystem()
        // $this->assertInstanceOf('Folder', $file->Parent(), 'Parent folder is created in database');
        // $this->assertFileExists($file->Parent()->getURL(), 'Parent folder is created on filesystem');
        // $this->assertEquals('FileTest', $file->Parent()->Name);
        // $this->assertInstanceOf('Folder', $file->Parent()->Parent(), 'Grandparent folder is created in database');
        // $this->assertFileExists($file->Parent()->Parent()->getURL(),
        // 'Grandparent folder is created on filesystem');
        // $this->assertEquals('assets', $file->Parent()->Parent()->Name);
    }

    public function testGetExtension()
    {
        $this->assertEquals(
            '',
            File::get_file_extension('myfile'),
            'No extension'
        );
        $this->assertEquals(
            'txt',
            File::get_file_extension('myfile.txt'),
            'Simple extension'
        );
        $this->assertEquals(
            'gz',
            File::get_file_extension('myfile.tar.gz'),
            'Double-barrelled extension only returns last bit'
        );
    }

    public function testValidateExtension()
    {
        $this->logOut();

        Config::modify()->set(File::class, 'allowed_extensions', ['txt']);

        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'asdf');

        // Invalid ext
        $file->Name = 'asdf.php';
        $result = $file->validate();
        $this->assertFalse($result->isValid());
        $messages = $result->getMessages();
        $this->assertEquals(1, count($messages ?? []));
        $this->assertEquals('Extension \'php\' is not allowed', $messages[0]['message']);

        // Valid ext
        $file->Name = 'asdf.txt';
        $result = $file->validate();
        $this->assertTrue($result->isValid());

        // Capital extension is valid as well
        $file->Name = 'asdf.TXT';
        $result = $file->validate();
        $this->assertTrue($result->isValid());
    }

    public function testInvalidImageManipulations()
    {
        // Existant non-image
        /** @var File $pdf */
        $pdf = $this->objFromFixture(File::class, 'pdf');
        $this->assertEquals(0, $pdf->getWidth());
        $this->assertEquals(0, $pdf->getHeight());
        $this->assertFalse($pdf->getIsImage());
        $this->assertTrue($pdf->exists());
        $this->assertNull($pdf->Pad(100, 100));

        // Non-existant image
        $image = new Image();
        $image->Filename = 'folder/some-non-file.jpg';
        $image->Hash = sha1('oogleeboogle');
        $image->write();
        $this->assertEquals(0, $image->getWidth());
        $this->assertEquals(0, $image->getHeight());
        $this->assertTrue($image->getIsImage());
        $this->assertFalse($image->exists());
        $this->assertNull($image->Pad(100, 100));

        // Existant but invalid files (see setUp())
        /** @var Image $broken */
        $broken = $this->objFromFixture(Image::class, 'gif');
        $this->assertEquals(0, $broken->getWidth());
        $this->assertEquals(0, $broken->getHeight());
        $this->assertTrue($broken->getIsImage());
        $this->assertTrue($broken->exists());
        $this->assertNull($broken->Pad(100, 100));
    }

    public function appCategoryDataProvider()
    {
        return [
            ['image', 'jpg'],
            ['image', 'JPG'],
            ['image', 'JPEG'],
            ['image', 'png'],
            ['image', 'tif'],
            ['image', 'webp'],
            ['document', 'pdf'],
            ['video', 'mov'],
            ['audio', 'OGG'],
        ];
    }

    /**
     * @dataProvider appCategoryDataProvider
     */
    public function testAppCategory($category, $extension)
    {
        // Test various categories
        $this->assertEquals($category, File::get_app_category($extension));
    }

    public function testGetCategoryExtensions()
    {
        // Test specific categories
        $images = [
            'alpha', 'als', 'bmp', 'cel', 'gif', 'ico', 'icon', 'jpeg', 'jpg', 'pcx', 'png', 'ps', 'psd', 'tif', 'tiff', 'webp'
        ];
        $this->assertEquals($images, File::get_category_extensions('image'));
        $this->assertEquals(
            ['bmp', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'webp'],
            File::get_category_extensions('image/supported')
        );
        $this->assertEquals($images, File::get_category_extensions(['image', 'image/supported']));
        $this->assertEquals(
            ['bmp', 'fla', 'gif', 'ico', 'jpeg', 'jpg', 'png', 'swf', 'webp'],
            File::get_category_extensions(['flash', 'image/supported'])
        );

        // Test other categories have at least one item
        $this->assertNotEmpty(File::get_category_extensions('archive'));
        $this->assertNotEmpty(File::get_category_extensions('audio'));
        $this->assertNotEmpty(File::get_category_extensions('document'));
        $this->assertNotEmpty(File::get_category_extensions('flash'));
        $this->assertNotEmpty(File::get_category_extensions('video'));
    }

    public function testAllFilesHaveCategory()
    {
        // Can't use dataprovider due to https://github.com/sebastianbergmann/phpunit/issues/1206
        foreach (array_filter(File::getAllowedExtensions() ?? []) as $ext) {
            $this->assertNotEmpty(
                File::get_app_category($ext),
                "Assert that extension {$ext} has a valid category"
            );
        }
    }

    public function testSetNameChangesFilesystemOnWrite()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'asdf');
        $this->logInWithPermission('ADMIN');
        $file->publishRecursive();
        $oldTuple = $file->File->getValue();

        // Rename
        $file->Name = 'renamed.txt';
        $newTuple = $oldTuple;
        $newTuple['Filename'] = $file->generateFilename();

        // Before write()
        $this->assertTrue(
            $this->getAssetStore()->exists($oldTuple['Filename'], $oldTuple['Hash']),
            'Old path is still present'
        );
        $this->assertFalse(
            $this->getAssetStore()->exists($newTuple['Filename'], $newTuple['Hash']),
            'New path is updated in memory, not written before write() is called'
        );

        // After write()
        $file->write();
        $this->assertTrue(
            $this->getAssetStore()->exists($oldTuple['Filename'], $oldTuple['Hash']),
            'Old path exists after draft change'
        );
        $this->assertTrue(
            $this->getAssetStore()->exists($newTuple['Filename'], $newTuple['Hash']),
            'New path is created after write()'
        );

        // After publish
        $file->publishRecursive();
        $this->assertFalse(
            $this->getAssetStore()->exists($oldTuple['Filename'], $oldTuple['Hash']),
            'Old file is finally removed after publishing new file'
        );
        $this->assertTrue(
            $this->getAssetStore()->exists($newTuple['Filename'], $newTuple['Hash']),
            'New path is created after write()'
        );
    }

    public function testSetParentIDChangesFilesystemOnWrite()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'asdf');
        $this->logInWithPermission('ADMIN');
        $file->publishRecursive();
        $subfolder = $this->objFromFixture(Folder::class, 'subfolder');
        $oldTuple = $file->File->getValue();

        // set ParentID
        $file->ParentID = $subfolder->ID;
        $newTuple = $oldTuple;
        $newTuple['Filename'] = $file->generateFilename();

        // Before write()
        $this->assertTrue(
            $this->getAssetStore()->exists($oldTuple['Filename'], $oldTuple['Hash']),
            'Old path is still present'
        );
        $this->assertFalse(
            $this->getAssetStore()->exists($newTuple['Filename'], $newTuple['Hash']),
            'New path is updated in memory, not written before write() is called'
        );

        // After write()
        $file->write();
        $this->assertTrue(
            $this->getAssetStore()->exists($oldTuple['Filename'], $oldTuple['Hash']),
            'Old path exists after draft change'
        );
        $this->assertTrue(
            $this->getAssetStore()->exists($newTuple['Filename'], $newTuple['Hash']),
            'New path is created after write()'
        );

        // After publish
        $file->publishSingle();
        $this->assertFalse(
            $this->getAssetStore()->exists($oldTuple['Filename'], $oldTuple['Hash']),
            'Old file is finally removed after publishing new file'
        );
        $this->assertTrue(
            $this->getAssetStore()->exists($newTuple['Filename'], $newTuple['Hash']),
            'New path is created after write()'
        );
    }

    /**
     * @see http://open.silverstripe.org/ticket/5693
     */
    public function testSetNameWithInvalidExtensionDoesntChangeFilesystem()
    {
        $this->expectException(ValidationException::class);
        Config::modify()->set(File::class, 'allowed_extensions', ['txt']);

        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'asdf');
        $file->Name = 'renamed.php'; // evil extension
        $file->write();
    }

    public function testGetURL()
    {
        /** @var File $rootfile */
        $rootfile = $this->objFromFixture(File::class, 'asdf');

        // Links to incorrect base (assets/ rather than assets/FileTest)
        // because ProtectedAdapter doesn't know about custom base dirs in TestAssetStore
        $this->assertEquals('/assets/55b443b601/FileTest.txt', $rootfile->getURL());

        // Login as ADMIN and grant session access by default
        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION);

        $this->assertEquals(['55b443b601/FileTest.txt' => true], $granted);
        $this->logOut();

        // Login as member with 'VIEW_DRAFT_CONTENT' permisson to access to file and get session access
        $this->logInWithPermission('VIEW_DRAFT_CONTENT');
        $this->assertEquals('/assets/55b443b601/FileTest.txt', $rootfile->getURL());

        $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION);
        $this->assertEquals(['55b443b601/FileTest.txt' => true], $granted);

        // Login as member of another Group that doesn't have permisson to access to file
        // and don't grant session access
        $rootfile->publishSingle();
        $this->logOut();
        $this->logInWithPermission('SOME_PERMISSIONS');

        $this->assertEquals('/assets/FileTest/FileTest.txt', $rootfile->getURL());

        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION);
        $this->assertNull($granted);
    }

    public function testGetSourceURL()
    {
        /** @var File $rootfile */
        $rootfile = $this->objFromFixture(File::class, 'asdf');

        // Links to incorrect base (assets/ rather than assets/FileTest)
        // because ProtectedAdapter doesn't know about custom base dirs in TestAssetStore
        $this->assertEquals('/assets/55b443b601/FileTest.txt', $rootfile->getSourceURL());

        // Login as ADMIN and grant session access by default
        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION);

        $this->assertEquals(['55b443b601/FileTest.txt' => true], $granted);
        $this->logOut();

        // Login as member with 'VIEW_DRAFT_CONTENT' permisson to access to file and get session access
        $this->logInWithPermission('VIEW_DRAFT_CONTENT');
        $this->assertEquals('/assets/55b443b601/FileTest.txt', $rootfile->getURL());

        $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION);
        $this->assertEquals(['55b443b601/FileTest.txt' => true], $granted);

        // Login as member of another Group that doesn't have permisson to access to file
        // and don't grant session access
        $rootfile->publishSingle();
        $this->logOut();
        $this->logInWithPermission('SOME_PERMISSIONS');

        $this->assertEquals('/assets/FileTest/FileTest.txt', $rootfile->getSourceURL());

        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION);
        $this->assertNull($granted);
    }

    public function testGetAbsoluteURL()
    {
        /** @var File $rootfile */
        $rootfile = $this->objFromFixture(File::class, 'asdf');

        // Links to incorrect base (assets/ rather than assets/FileTest)
        // because ProtectedAdapter doesn't know about custom base dirs in TestAssetStore
        $this->assertEquals(
            Controller::join_links(Director::absoluteBaseURL(), 'assets/55b443b601/FileTest.txt'),
            $rootfile->getAbsoluteURL()
        );

        $rootfile->publishSingle();
        $this->assertEquals(
            Controller::join_links(Director::absoluteBaseURL(), 'assets/FileTest/FileTest.txt'),
            $rootfile->getAbsoluteURL()
        );
    }

    public function testNameAndTitleGeneration()
    {
        // When name is assigned, title is automatically assigned
        /** @var Image $file */
        $file = $this->objFromFixture(Image::class, 'setfromname');
        $this->assertEquals('FileTest', $file->Title);
    }

    public function testSizeAndAbsoluteSizeParameters()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'asdf');

        /* AbsoluteSize will give the integer number */
        $this->assertEquals(1000000, $file->getAbsoluteSize());
        /* Size will give a humanised number */
        $this->assertEquals('977 KB', $file->getSize());
    }

    public function testFileType()
    {
        /** @var Image $file */
        $file = $this->objFromFixture(Image::class, 'gif');
        $this->assertEquals("GIF image - good for diagrams", $file->getFileType());

        $file = $this->objFromFixture(File::class, 'brf');
        $this->assertEquals("Braille ASCII file", $file->getFileType());

        $file = $this->objFromFixture(File::class, 'pdf');
        $this->assertEquals("Adobe Acrobat PDF file", $file->getFileType());

        $file = $this->objFromFixture(Image::class, 'gifupper');
        $this->assertEquals("GIF image - good for diagrams", $file->getFileType());

        /* Only a few file types are given special descriptions; the rest are unknown */
        $file = $this->objFromFixture(File::class, 'asdf');
        $this->assertEquals("unknown", $file->getFileType());
    }

    /**
     * Test the File::format_size() method
     */
    public function testFormatSize()
    {
        $this->assertEquals("1000 bytes", File::format_size(1000));
        $this->assertEquals("1023 bytes", File::format_size(1023));
        $this->assertEquals("1 KB", File::format_size(1025));
        $this->assertEquals("10 KB", File::format_size(10000));
        $this->assertEquals("49 KB", File::format_size(50000));
        $this->assertEquals("977 KB", File::format_size(1000000));
        $this->assertEquals("1 MB", File::format_size(1024*1024));
        $this->assertEquals("954 MB", File::format_size(1000000000));
        $this->assertEquals("1 GB", File::format_size(1024*1024*1024));
        $this->assertEquals("9.3 GB", File::format_size(10000000000));
        // It use any denomination higher than GB.  It also doesn't overflow with >32 bit integers
        $this->assertEquals("93132.3 GB", File::format_size(100000000000000));
    }

    public function testDeleteFile()
    {
        /**
         * @var File $file
         */
        $file = $this->objFromFixture(File::class, 'asdf');
        $this->logInWithPermission('ADMIN');
        $file->publishSingle();
        $tuple = $file->File->getValue();

        // Before delete
        $this->assertTrue(
            $this->getAssetStore()->exists($tuple['Filename'], $tuple['Hash']),
            'File is still present'
        );

        // after unpublish
        $file->doUnpublish();
        $this->assertTrue(
            $this->getAssetStore()->exists($tuple['Filename'], $tuple['Hash']),
            'File is still present after unpublish'
        );

        // after delete
        $file->delete();
        $this->assertFalse(
            $this->getAssetStore()->exists($tuple['Filename'], $tuple['Hash']),
            'File is deleted after unpublish and delete'
        );
    }

    public function testRenameFolder()
    {
        $newTitle = "FileTest-folder-renamed";

        //rename a folder's title
        $folderID = $this->objFromFixture(Folder::class, "folder2")->ID;
        /** @var Folder $folder */
        $folder = DataObject::get_by_id(Folder::class, $folderID);
        $folder->Title = $newTitle;
        $folder->write();

        //get folder again and see if the filename has changed
        /** @var Folder $folder */
        $folder = DataObject::get_by_id(Folder::class, $folderID);
        $this->assertEquals(
            $newTitle . '/',
            $folder->getFilename(),
            "Folder Filename updated after rename of Title"
        );

        //rename a folder's name
        $newTitle2 = "FileTest-folder-renamed2";
        $folder->Name = $newTitle2;
        $folder->write();

        //get folder again and see if the Title has changed
        /** @var Folder $folder */
        $folder = DataObject::get_by_id(Folder::class, $folderID);
        $this->assertEquals(
            $folder->Title,
            $newTitle2,
            "Folder Title updated after rename of Name"
        );


        //rename a folder's Filename
        $newTitle3 = "FileTest-folder-renamed3";
        $folder->Filename = $newTitle3;
        $folder->write();

        //get folder again and see if the Title has changed
        $folder = DataObject::get_by_id(Folder::class, $folderID);
        $this->assertEquals(
            $folder->Title,
            $newTitle3,
            "Folder Title updated after rename of Filename"
        );
    }

    public function testRenamesDuplicateFilesInSameFolder()
    {
        $original = new File();
        $original->update([
            'Name' => 'file1.txt',
            'ParentID' => 0
        ]);
        $original->write();

        $duplicate = new File();
        $duplicate->update([
            'Name' => 'file1.txt',
            'ParentID' => 0
        ]);
        $duplicate->write();

        /** @var File $original */
        $original = File::get()->byID($original->ID);

        $this->assertEquals($original->Name, 'file1.txt');
        $this->assertEquals($original->Title, 'file1');
        $this->assertEquals($duplicate->Name, 'file1-v2.txt');
        $this->assertEquals($duplicate->Title, 'file1 v2');
    }

    public function testSetsEmptyTitleToNameWithoutExtensionAndSpecialCharacters()
    {
        $fileWithTitle = new File();
        $fileWithTitle->update([
            'Name' => 'file1-with-title.txt',
            'Title' => 'Some Title'
        ]);
        $fileWithTitle->write();

        $this->assertEquals($fileWithTitle->Name, 'file1-with-title.txt');
        $this->assertEquals($fileWithTitle->Title, 'Some Title');

        $fileWithoutTitle = new File();
        $fileWithoutTitle->update([
            'Name' => 'file1-without-title.txt',
        ]);
        $fileWithoutTitle->write();

        $this->assertEquals($fileWithoutTitle->Name, 'file1-without-title.txt');
        $this->assertEquals($fileWithoutTitle->Title, 'file1 without title');
    }

    public function testSetsEmptyNameToSingularNameWithoutTitle()
    {
        $fileWithTitle = new File();
        $fileWithTitle->update([
            'Name' => '',
            'Title' => 'Some Title',
        ]);
        $fileWithTitle->write();

        $this->assertEquals($fileWithTitle->Name, 'Some-Title');
        $this->assertEquals($fileWithTitle->Title, 'Some Title');

        $fileWithoutTitle = new File();
        $fileWithoutTitle->update([
            'Name' => '',
            'Title' => '',
        ]);
        $fileWithoutTitle->write();

        $this->assertEquals($fileWithoutTitle->Name, $fileWithoutTitle->i18n_singular_name());
        $this->assertEquals($fileWithoutTitle->Title, $fileWithoutTitle->i18n_singular_name());
    }

    public function testSetsEmptyNameToTitleIfPresent()
    {
        $file = new File();
        $file->update([
            'Name' => '',
            'Title' => 'file1',
        ]);
        $file->write();

        $this->assertEquals($file->Name, 'file1');
        $this->assertEquals($file->Title, 'file1');
    }

    public function testSetsOwnerOnFirstWrite()
    {
        $this->logOut();
        $member1 = new Member();
        $member1->write();
        $member2 = new Member();
        $member2->write();

        $file1 = new File();
        $file1->write();
        $this->assertEquals(0, $file1->OwnerID, 'Owner not written when no user is logged in');

        $this->logInAs($member1);
        $file2 = new File();
        $file2->write();
        $this->assertEquals($member1->ID, $file2->OwnerID, 'Owner written when user is logged in');

        $this->logInAs($member2);
        $file2->forceChange();
        $file2->write();
        $this->assertEquals($member1->ID, $file2->OwnerID, 'Owner not overwritten on existing files');
    }

    public function testCanEdit()
    {
        $file = $this->objFromFixture(Image::class, 'gif');
        $secureFile = $this->objFromFixture(File::class, 'restrictedFolder-file3');

        // Test anonymous permissions
        $this->logOut();
        $this->assertFalse($file->canEdit(), "Anonymous users can't edit files");

        // Test permissionless user
        $frontendUser = $this->objFromFixture(Member::class, 'frontend');
        $this->assertFalse($file->canEdit($frontendUser), "Permissionless users can't edit files");

        // Test global CMS section users
        $cmsUser = $this->objFromFixture(Member::class, 'cms');
        $this->assertFalse($file->canEdit($cmsUser), "CMS access doesn't necessarily grant edit permissions");

        // Test cms access users without file access
        $securityUser = $this->objFromFixture(Member::class, 'security');
        $this->assertFalse($file->canEdit($securityUser), "Security CMS users can't edit files");

        // Asset-admin user can edit files their group is granted to
        $assetAdminUser = $this->objFromFixture(Member::class, 'assetadmin');
        $this->assertFalse($file->canEdit($assetAdminUser), "Asset admin users can edit files");
        $this->assertTrue($secureFile->canEdit($assetAdminUser), "Asset admin can edit assigned files");

        // FILE_EDIT_ALL users can edit all
        $fileUser = $this->objFromFixture(Member::class, 'file');
        $this->assertTrue($file->canEdit($fileUser));
        $this->assertTrue($secureFile->canEdit($fileUser));

        // Test admin
        $admin = $this->objFromFixture(Member::class, 'admin');
        $this->assertTrue($file->canEdit($admin), "Admins can edit files");
        $this->assertTrue($secureFile->canEdit($admin), 'Admins can edit any files');
    }

    public function testCanView()
    {
        $file = $this->objFromFixture(Image::class, 'gif');
        $secureFile = $this->objFromFixture(File::class, 'restrictedViewFolder-file4');

        // Test anonymous permissions
        $this->logOut();
        $this->assertFalse($file->canView(), "Anonymous users cannot view draft files");
        $file->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        $this->assertTrue($file->canView(), "Anonymous users can view public files");
        $this->assertFalse($secureFile->canView(), "Anonymous users cannot view files with specific permissions");
        // Test permissionless user
        $frontendUser = $this->objFromFixture(Member::class, 'frontend');
        $this->assertTrue($file->canView($frontendUser), "Permissionless users can view files");
        $this->assertFalse($secureFile->canView($frontendUser), "Permissionless users cannot view secure files");

        // Test global CMS section users
        $cmsUser = $this->objFromFixture(Member::class, 'cms');
        $this->assertTrue($file->canView($cmsUser), "CMS users can view public files");
        $this->assertFalse($secureFile->canView($cmsUser), "CMS users cannot view a file that is not assigned to them");

        // Test cms access users without file access
        $securityUser = $this->objFromFixture(Member::class, 'security');
        $this->assertTrue($file->canView($securityUser), "Security CMS users can view public files");
        $this->assertFalse($secureFile->canView($securityUser), "Security CMS users cannot view a file that is not assigned to them.");

        // Asset-admin user can edit files their group is granted to
        $assetAdminUser = $this->objFromFixture(Member::class, 'assetadmin');
        $this->assertTrue($file->canView($assetAdminUser), "Asset admin users can view public files");
        $this->assertTrue($secureFile->canView($assetAdminUser), "Asset admin can view files that are assigned to them");

        // Test admin
        $admin = $this->objFromFixture(Member::class, 'admin');
        $this->assertTrue($file->canView($admin), "Admins can view files");
        $this->assertTrue($secureFile->canView($admin), 'Admins can view files that are not necessarily assigned to them');
    }

    public function testCanCreate()
    {
        $normalFolder = $this->objFromFixture(Folder::class, 'folder1');
        $restrictedFolder = $this->objFromFixture(Folder::class, 'restrictedFolder');

        // CMS user without any other permissions can't create files
        $cmsUser = $this->objFromFixture(Member::class, 'cms');
        $this->assertFalse(File::singleton()->canCreate($cmsUser, [
            'Parent' => $restrictedFolder,
        ]));
        $this->assertFalse(File::singleton()->canCreate($cmsUser, [
            'Parent' => $normalFolder,
        ]));
        $this->assertFalse(File::singleton()->canCreate($cmsUser));

        // Members of the specific group can create in this folder (only)
        $assetAdminUser = $this->objFromFixture(Member::class, 'assetadmin');
        $this->assertTrue(File::singleton()->canCreate($assetAdminUser, [
            'Parent' => $assetAdminUser,
        ]));
        $this->assertFalse(File::singleton()->canCreate($assetAdminUser, [
            'Parent' => $normalFolder,
        ]));
        $this->assertFalse(File::singleton()->canCreate($assetAdminUser));

        // user with FILE_EDIT_ALL permission can edit any file
        $fileUser = $this->objFromFixture(Member::class, 'file');
        $this->assertTrue(File::singleton()->canCreate($fileUser, [
            'Parent' => $restrictedFolder,
        ]));
        $this->assertTrue(File::singleton()->canCreate($fileUser, [
            'Parent' => $normalFolder,
        ]));
        $this->assertTrue(File::singleton()->canCreate($fileUser));
    }

    public function testJoinPaths()
    {
        $this->assertEquals('name/file.jpg', File::join_paths('/name', 'file.jpg'));
        $this->assertEquals('name/file.jpg', File::join_paths('name', 'file.jpg'));
        $this->assertEquals('name/file.jpg', File::join_paths('/name', '/file.jpg'));
        $this->assertEquals('name/file.jpg', File::join_paths('name/', '/', 'file.jpg'));
        $this->assertEquals('file.jpg', File::join_paths('/', '/', 'file.jpg'));
        $this->assertEquals('', File::join_paths('/', '/'));
    }

    /**
     * @return AssetStore
     */
    protected function getAssetStore()
    {
        return Injector::inst()->get(AssetStore::class);
    }

    public function testRename()
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'asdf');
        $this->assertTrue($file->exists());
        $this->assertEquals('FileTest.txt', $file->getFilename());
        $this->assertEquals('FileTest.txt', $file->File->getFilename());

        // Rename immediately saves record and moves to new location
        $result = $file->renameFile('_Parent/New__File.txt');
        $this->assertTrue($file->exists());
        $this->assertEquals('Parent/New_File.txt', $result);
        $this->assertEquals('Parent/New_File.txt', $file->generateFilename());
        $this->assertEquals('Parent/New_File.txt', $file->getFilename());
        $this->assertFalse($file->isChanged());
    }

    /**
     * @dataProvider allowedExtensionsProvider
     * @param array $allowedExtensions
     * @param array $expected
     */
    public function testGetAllowedExtensions($allowedExtensions, $expected)
    {
        Config::modify()->set(File::class, 'allowed_extensions', $allowedExtensions);
        $this->assertSame(array_values($expected ?? []), array_values(File::getAllowedExtensions() ?? []));
    }

    /**
     * @return array[]
     */
    public function allowedExtensionsProvider()
    {
        return [
            'unkeyed array' => [
                ['jpg', 'foo', 'gif', 'bmp'],
                ['jpg', 'foo', 'gif', 'bmp'],
            ],
            'associative array' => [
                ['jpg' => true, 'foo' => false, 'gif' => true, 'bmp' => true],
                ['jpg', 'gif', 'bmp'],
            ],
            'mixed array' => [
                ['jpg', 'foo', 'gif' => false, 'bmp' => true],
                ['jpg', 'foo', 'bmp'],
            ],
            'mixed array with removals' => [
                ['jpg', 'foo', 'gif', 'bmp' => true, 'foo' => false],
                ['jpg', 'gif', 'bmp'],
            ],
            'mixed cases with removals' => [
                ['jpg' => null, 'FOO', 'gIf', 'bmP'],
                ['foo', 'gif', 'bmp'],
            ]
        ];
    }

    public function testCanViewReturnsExtendedResult()
    {
        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(true);
        $this->assertTrue($file->canView());
    }

    public function testCanViewDelegatesToParentWhenInheritingPermissions()
    {
        $this->logOut();

        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(null);

        /** @var PermissionChecker&MockObject $permissionChecker */
        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->expects($this->once())->method('canView')->with(123)->willReturn(false);
        Injector::inst()->registerService($permissionChecker, PermissionChecker::class . '.file');

        $file->CanViewType = 'Inherit';
        $file->ParentID = 123;
        $this->assertFalse($file->canView());
    }

    public function testCanViewInheritsRecursively()
    {
        $this->logOut();
        $folderA = Folder::create([
            'Name' => 'Grandparent',
            'CanViewType' => 'LoggedInUsers',
        ]);
        $folderA->write();

        $folderB = Folder::create([
            'Name' => 'Parent',
            'ParentID' => $folderA->ID,
            'CanViewtype' => 'Inherit',
        ]);
        $folderB->write();
        $file = File::create([
            'Name' => 'File',
            'ParentID' => $folderB->ID,
            'CanViewType' => 'Inherit',
        ]);
        $file->write();
        $file->publishRecursive();

        $this->assertFalse($file->canView());

        $this->logInWithPermission('ADMIN');
        $this->assertTrue($file->canView());

        $folderA->CanViewType = 'Anyone';
        $folderA->write();

        $this->logOut();
        $this->assertTrue($file->canView());
    }

    public function testCanViewReturnsFalseForAnonymousUsersWithCanViewTypeLoggedInUsers()
    {
        $this->logOut();

        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(null);

        $file->CanViewType = 'LoggedInUsers';
        $this->assertFalse($file->canView());
    }

    public function testCanViewReturnsFalseForAnonymousUsersWithCanViewTypeOnlyTheseUsers()
    {
        $this->logOut();

        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(null);

        $file->CanViewType = 'OnlyTheseUsers';
        $this->assertFalse($file->canView());
    }

    public function testCanViewReturnsTrueForUserInGroupWithCanViewTypeOnlyTheseUsers()
    {
        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(null);

        /** @var Member&MockObject $member */
        $member = $this->createMock(Member::class);
        $member->expects($this->once())->method('inGroups')->willReturn(true);

        $file->CanViewType = 'OnlyTheseUsers';
        $this->assertTrue($file->canView($member));
    }

    public function testCanViewFallsBackToCheckingDefaultFilePermissions()
    {
        $this->logOut();

        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(null);

        /** @var PermissionChecker&MockObject $permissionChecker */
        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->expects($this->once())->method('canView')->with(234)->willReturn(true);
        Injector::inst()->registerService($permissionChecker, PermissionChecker::class . '.file');

        $file->CanViewType = 'Anyone';
        $file->ID = 234;
        $this->assertTrue($file->canView());
    }

    public function testCanEditReturnsExtendedResult()
    {
        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(true);
        $this->assertTrue($file->canEdit());
    }

    public function testCanEditReturnsTrueForUserWithEditAllPermissions()
    {
        $this->logInWithPermission();

        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(null);
        $this->assertTrue($file->canEdit());
    }

    public function testCanEditDelegatesToParentWhenInheritingPermissions()
    {
        $this->logOut();

        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(null);

        /** @var PermissionChecker&MockObject $permissionChecker */
        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->expects($this->once())->method('canEdit')->with(123)->willReturn(false);
        Injector::inst()->registerService($permissionChecker, PermissionChecker::class . '.file');

        $file->CanEditType = 'Inherit';
        $file->ParentID = 123;
        $this->assertFalse($file->canEdit());
    }

    public function testCanEditFallsBackToCheckingDefaultFilePermissions()
    {
        $this->logOut();

        /** @var File&MockObject $file */
        $file = $this->getMockBuilder(File::class)->setMethods(['extendedCan'])->getMock();
        $file->expects($this->once())->method('extendedCan')->willReturn(null);

        /** @var PermissionChecker&MockObject $permissionChecker */
        $permissionChecker = $this->createMock(PermissionChecker::class);
        $permissionChecker->expects($this->once())->method('canEdit')->with(234)->willReturn(false);
        Injector::inst()->registerService($permissionChecker, PermissionChecker::class . '.file');

        $file->CanEditType = 'Anyone';
        $file->ID = 234;
        $this->assertFalse($file->canEdit());
    }

    /**
     * @return Generator
     * @see testHasRestrictedAccess
     */
    public function restrictedAccessDataProvider()
    {
        yield ['restricted-test-r', false];
        yield ['restricted-test-r1', false];
        yield ['restricted-test-r11', false];
        yield ['restricted-test-r111', false];
        yield ['restricted-test-r12', true];
        yield ['restricted-test-r121', true];
        yield ['restricted-test-r2', true];
        yield ['restricted-test-r21', true];
        yield ['restricted-test-r211', true];
        yield ['restricted-test-r22', true];
        yield ['restricted-test-r221', false];
    }

    /**
     * @dataProvider restrictedAccessDataProvider
     *
     * @param string $fixtureName
     * @param bool $expected
     */
    public function testHasRestrictedAccess(string $fixtureName, bool $expected)
    {
        /** @var Folder $folder */
        $folder = $this->objFromFixture(Folder::class, $fixtureName);
        $this->assertSame($expected, $folder->hasRestrictedAccess());
    }

    private function createModifiedFile()
    {
        $file = File::create();
        $store = $this->getAssetStore();

        // 'a1c09d076de11aabc32b73ae2caca01f2b0533d9';
        $file->setFromString('first version', 'file.txt');
        $firstHash = $file->getHash();
        $file->publishSingle();

        // 'c7e7f59fa9aaed3df124d32e60982726a40568f9';
        $file->setFromString('second version', 'file.txt');
        $secondHash = $file->getHash();
        $file->write();

        return [$file, $store, $firstHash, $secondHash];
    }

    /**
     * Validate the private helper function above works correctly
     */
    public function testCreateModifiedFile()
    {
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFile();

        // Assert that our test file is a modified state with 2 different physical files
        $this->assertTrue($file->stagesDiffer());
        $this->assertSame(AssetStore::VISIBILITY_PUBLIC, $store->getVisibility('file.txt', $firstHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file.txt', $secondHash));
    }

    private function createModifiedFileWithDifferentFilename()
    {
        $file = File::create();
        $store = $this->getAssetStore();

        // 'a1c09d076de11aabc32b73ae2caca01f2b0533d9';
        $file->setFromString('first version', 'file.txt');
        $firstHash = $file->getHash();
        $file->publishSingle();

        // 'c7e7f59fa9aaed3df124d32e60982726a40568f9';
        $file->setFromString('second version', 'file-changed.txt');
        $secondHash = $file->getHash();
        $file->write();

        return [$file, $store, $firstHash, $secondHash];
    }

    /**
     * Validate the private helper function above works correctly
     */
    public function testCreateModifiedFileWithDifferentFilename()
    {
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFileWithDifferentFilename();

        // Assert that our test file is a modified state with 2 different physical files
        $this->assertTrue($file->stagesDiffer());
        $this->assertSame(AssetStore::VISIBILITY_PUBLIC, $store->getVisibility('file.txt', $firstHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file-changed.txt', $secondHash));
    }

    /**
     * Assert that unpublishing a modified file dataObject removes the live file only
     */
    public function testUnpublishingModifiedDeletesLiveFile()
    {
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFile();
        $file->doUnpublish();
        $this->assertFalse($store->exists('file.txt', $firstHash));
        $this->assertTrue($store->exists('file.txt', $secondHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file.txt', $secondHash));
    }

    /**
     * Assert that unpublishing a modified file dataObject with keep_archived_assets moves the live file
     * to the protected store
     */
    public function testUnpublishingModifiedKeepArchivedLiveFile()
    {
        Config::modify()->set(File::class, 'keep_archived_assets', true);
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFile();
        $file->doUnpublish();
        $this->assertTrue($store->exists('file.txt', $firstHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file.txt', $firstHash));
        $this->assertTrue($store->exists('file.txt', $secondHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file.txt', $secondHash));
    }

    /**
     * Assert that archiving a modified file dataObject removes both physical files
     */
    public function testArchivingModifiedDeletesBothPhysicalFiles()
    {
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFile();
        $file->doArchive();
        $this->assertFalse($store->exists('file.txt', $firstHash));
        $this->assertFalse($store->exists('file.txt', $secondHash));
    }

    /**
     * Assert that archiving a modified file dataObject with keep_archived_assets moves both files
     * to the protected store
     */
    public function testArchivingModifiedKeepArchivedBothPhysicalFiles()
    {
        Config::modify()->set(File::class, 'keep_archived_assets', true);
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFile();
        $file->doArchive();
        $this->assertTrue($store->exists('file.txt', $firstHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file.txt', $firstHash));
        $this->assertTrue($store->exists('file.txt', $secondHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file.txt', $secondHash));
    }

    /**
     * Assert that archiving a modified file dataObject removes the live file only
     * (draft file has a different filename)
     */
    public function testUnpublishingModifiedDeletesLiveFileWithDifferentFilename()
    {
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFileWithDifferentFilename();
        $file->doUnpublish();
        $this->assertFalse($store->exists('file.txt', $firstHash));
        $this->assertFalse($store->exists('file.txt', $secondHash));
        $this->assertFalse($store->exists('file-changed.txt', $firstHash));
        $this->assertTrue($store->exists('file-changed.txt', $secondHash));
    }

    /**
     * Assert that archiving a modified file dataObject with keep_archived_assets moves the live file only to the
     * protected store (draft file has a different filename)
     */
    public function testUnpublishingModifiedKeepArchivedLiveFileWithDifferentFilename()
    {
        Config::modify()->set(File::class, 'keep_archived_assets', true);
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFileWithDifferentFilename();
        $file->doUnpublish();
        $this->assertTrue($store->exists('file.txt', $firstHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file.txt', $firstHash));
        $this->assertFalse($store->exists('file.txt', $secondHash));
        $this->assertFalse($store->exists('file-changed.txt', $firstHash));
        $this->assertTrue($store->exists('file-changed.txt', $secondHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file-changed.txt', $secondHash));
    }

    /**
     * Assert that archiving a modified file dataObject removes both physical files
     * (draft file has a different filename)
     */
    public function testArchivingModifiedDeletesBothPhysicalFilesWithDifferentFilenames()
    {
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFileWithDifferentFilename();
        $file->doArchive();
        $this->assertFalse($store->exists('file.txt', $firstHash));
        $this->assertFalse($store->exists('file.txt', $secondHash));
        $this->assertFalse($store->exists('file-changed.txt', $firstHash));
        $this->assertFalse($store->exists('file-changed.txt', $secondHash));
    }

    /**
     * Assert that archiving a modified file dataObject with keep_archived_assets moves both physical files to the
     * protected store (draft file has a different filename)
     */
    public function testArchivingModifiedKeepArchivedBothPhysicalFilesWithDifferentFilenames()
    {
        Config::modify()->set(File::class, 'keep_archived_assets', true);
        list($file, $store, $firstHash, $secondHash) = $this->createModifiedFileWithDifferentFilename();
        $file->doArchive();
        $this->assertTrue($store->exists('file.txt', $firstHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file.txt', $firstHash));
        $this->assertFalse($store->exists('file.txt', $secondHash));
        $this->assertFalse($store->exists('file-changed.txt', $firstHash));
        $this->assertTrue($store->exists('file-changed.txt', $secondHash));
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $store->getVisibility('file-changed.txt', $secondHash));
    }

    public function testMoveFileRenamesDuplicateFilename()
    {
        $folder1 = $this->objFromFixture(Folder::class, 'folder1');
        $folder2 = $this->objFromFixture(Folder::class, 'folder2');
        $file1 = $this->objFromFixture(File::class, 'pdf');
        $file1->ParentID = $folder1->ID;
        $file1->write();
        $file2 = File::create([
            'FileFilename' => $file1->FileFilename,
            'FileHash' => $file1->FileHash,
            'Name' => $file1->Name,
            'ParentID' => $folder2->ID,
        ]);
        $file2->write();
        $this->assertTrue(strpos($file1->getFilename(), 'FileTest.pdf') !== false);
        $this->assertTrue(strpos($file2->getFilename(), 'FileTest.pdf') !== false);
        // Move file1 to folder2 and ensure it gets renamed as it would have a duplicate filename
        $file1->ParentID = $folder2->ID;
        $file1->write();
        $this->assertTrue(strpos($file1->getFilename(), 'FileTest-v2.pdf') !== false);
        $this->assertTrue(strpos($file2->getFilename(), 'FileTest.pdf') !== false);
    }
}

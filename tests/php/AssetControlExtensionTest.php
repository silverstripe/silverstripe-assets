<?php

namespace SilverStripe\Assets\Tests;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Assets\Tests\AssetControlExtensionTest\ArchivedObject;
use SilverStripe\Assets\Tests\AssetControlExtensionTest\TestObject;
use SilverStripe\Assets\Tests\AssetControlExtensionTest\VersionedObject;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * Tests {@see AssetControlExtension}
 * @skipUpgrade
 */
class AssetControlExtensionTest extends SapphireTest
{

    protected static $extra_dataobjects = [
        VersionedObject::class,
        TestObject::class
    ];

    public function setUp()
    {
        parent::setUp();

        // Set backend and base url
        Versioned::set_stage(Versioned::DRAFT);
        TestAssetStore::activate('AssetControlExtensionTest');
        $this->logInWithPermission('ADMIN');

        // Setup fixture manually
        $object1 = new AssetControlExtensionTest\VersionedObject();
        $object1->Title = 'My object';
        $fish1 = realpath(__DIR__ .'/ImageTest/test-image-high-quality.jpg');
        $object1->Header->setFromLocalFile($fish1, 'Header/MyObjectHeader.jpg');
        $object1->Download->setFromString('file content', 'Documents/File.txt');
        $object1->write();
        $object1->publishSingle();

        $object2 = new AssetControlExtensionTest\TestObject();
        $object2->Title = 'Unversioned';
        $object2->Image->setFromLocalFile($fish1, 'Images/BeautifulFish.jpg');
        $object2->write();

        $object3 = new AssetControlExtensionTest\ArchivedObject();
        $object3->Title = 'Archived';
        $object3->Header->setFromLocalFile($fish1, 'Archived/MyObjectHeader.jpg');
        $object3->write();
        $object3->publishSingle();
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testFileDelete()
    {
        Versioned::set_stage(Versioned::DRAFT);

        /**
         * @var VersionedObject $object1
        */
        $object1 = AssetControlExtensionTest\VersionedObject::get()
                ->filter('Title', 'My object')
                ->first();
        /**
         * @var Object $object2
        */
        $object2 = AssetControlExtensionTest\TestObject::get()
                ->filter('Title', 'Unversioned')
                ->first();

        /**
         * @var ArchivedObject $object3
        */
        $object3 = AssetControlExtensionTest\ArchivedObject::get()
                ->filter('Title', 'Archived')
                ->first();

        $this->assertTrue($object1->Download->exists());
        $this->assertTrue($object1->Header->exists());
        $this->assertTrue($object2->Image->exists());
        $this->assertTrue($object3->Header->exists());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object1->Download->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object1->Header->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object2->Image->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object3->Header->getVisibility());

        // Check live stage for versioned objects
        $object1Live = Versioned::get_one_by_stage(
            VersionedObject::class,
            'Live',
            ['"ID"' => $object1->ID]
        );
        $object3Live = Versioned::get_one_by_stage(
            ArchivedObject::class,
            'Live',
            ['"ID"' => $object3->ID]
        );
        $this->assertTrue($object1Live->Download->exists());
        $this->assertTrue($object1Live->Header->exists());
        $this->assertTrue($object3Live->Header->exists());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object1Live->Download->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object1Live->Header->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object3Live->Header->getVisibility());

        // Delete live records; Should cause versioned records to be protected
        $object1Live->deleteFromStage('Live');
        $object3Live->deleteFromStage('Live');
        $this->assertTrue($object1->Download->exists());
        $this->assertTrue($object1->Header->exists());
        $this->assertTrue($object3->Header->exists());
        $this->assertTrue($object1Live->Download->exists());
        $this->assertTrue($object1Live->Header->exists());
        $this->assertTrue($object3Live->Header->exists());
        $this->assertEquals(AssetStore::VISIBILITY_PROTECTED, $object1->Download->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PROTECTED, $object1->Header->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PROTECTED, $object3->Header->getVisibility());

        // Delete draft record; Should remove all records
        // Archived assets only should remain
        $object1->delete();
        $object2->delete();
        $object3->delete();

        $this->assertFalse($object1->Download->exists());
        $this->assertFalse($object1->Header->exists());
        $this->assertFalse($object2->Image->exists());
        $this->assertTrue($object3->Header->exists());
        $this->assertFalse($object1Live->Download->exists());
        $this->assertFalse($object1Live->Header->exists());
        $this->assertTrue($object3Live->Header->exists());
        $this->assertNull($object1->Download->getVisibility());
        $this->assertNull($object1->Header->getVisibility());
        $this->assertNull($object2->Image->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PROTECTED, $object3->Header->getVisibility());
    }

    /**
     * Test files being replaced
     */
    public function testReplaceFile()
    {
        Versioned::set_stage(Versioned::DRAFT);

        /**
         * @var VersionedObject $object1
        */
        $object1 = AssetControlExtensionTest\VersionedObject::get()
                ->filter('Title', 'My object')
                ->first();
        /**
         * @var Object $object2
        */
        $object2 = AssetControlExtensionTest\TestObject::get()
                ->filter('Title', 'Unversioned')
                ->first();

        /**
         * @var ArchivedObject $object3
        */
        $object3 = AssetControlExtensionTest\ArchivedObject::get()
                ->filter('Title', 'Archived')
                ->first();

        $object1TupleOld = $object1->Header->getValue();
        $object2TupleOld = $object2->Image->getValue();
        $object3TupleOld = $object3->Header->getValue();

        // Replace image and write each to filesystem
        $fish1 = realpath(__DIR__ .'/ImageTest/test-image-high-quality.jpg');
        $object1->Header->setFromLocalFile($fish1, 'Header/Replaced_MyObjectHeader.jpg');
        $object1->write();
        $object2->Image->setFromLocalFile($fish1, 'Images/Replaced_BeautifulFish.jpg');
        $object2->write();
        $object3->Header->setFromLocalFile($fish1, 'Archived/Replaced_MyObjectHeader.jpg');
        $object3->write();

        // Check that old published records are left public, but removed for unversioned object2
        $this->assertEquals(
            AssetStore::VISIBILITY_PUBLIC,
            $this->getAssetStore()->getVisibility($object1TupleOld['Filename'], $object1TupleOld['Hash'])
        );
        $this->assertEquals(
            null, // Old file is destroyed
            $this->getAssetStore()->getVisibility($object2TupleOld['Filename'], $object2TupleOld['Hash'])
        );
        $this->assertEquals(
            AssetStore::VISIBILITY_PUBLIC,
            $this->getAssetStore()->getVisibility($object3TupleOld['Filename'], $object3TupleOld['Hash'])
        );

        // Check that visibility of new file is correct
        // Note that $object2 has no canView() is true, so assets end up public
        $this->assertEquals(AssetStore::VISIBILITY_PROTECTED, $object1->Header->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object2->Image->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PROTECTED, $object3->Header->getVisibility());

        // Publish changes to versioned records
        $object1->publishSingle();
        $object3->publishSingle();

        // After publishing, old object1 is deleted, but since object3 has archiving enabled,
        // the orphaned file is intentionally left in the protected store
        $this->assertEquals(
            null,
            $this->getAssetStore()->getVisibility($object1TupleOld['Filename'], $object1TupleOld['Hash'])
        );
        $this->assertEquals(
            AssetStore::VISIBILITY_PROTECTED,
            $this->getAssetStore()->getVisibility($object3TupleOld['Filename'], $object3TupleOld['Hash'])
        );

        // And after publish, all files are public
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object1->Header->getVisibility());
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $object3->Header->getVisibility());
    }

    
    public function testReplaceWithVariant()
    {
        $store = $this->getAssetStore();
        $object1 = AssetControlExtensionTest\VersionedObject::get()->filter('Title', 'My object')->first();
        /** @var DBFile $download */
        $download = $object1->Download;
        Versioned::set_stage(Versioned::DRAFT);
        $v2Content = 'Documents/File.txt v2';
        $v3Content = 'Documents/File.txt v3';

        $v1 = new ParsedFileID($download->getFilename(), $download->getHash());
        $v2 = $v1->setHash(sha1($v2Content));
        $v3 = $v1->setHash(sha1($v3Content));

        // Start by creating a pre-existing variant
        $download->setFromString(
            'Variant of Documents/File.txt',
            $v1->getFilename(),
            $v1->getHash(),
            'boom'
        );
        $this->assertTrue(
            $store->exists($v1->getFilename(), $v1->getHash(), 'boom'),
            sprintf('A variant of %s has been created', $v1->getFilename())
        );
        
        // Let's replace the content of the main file and publish it
        $download->setFromString($v2Content, $v2->getFilename());
        $object1->write();
        $object1->publishSingle();
        $this->assertFalse(
            $store->exists($v1->getFilename(), $v1->getHash()),
            'Original file has been deleted'
        );
        $this->assertFalse(
            $store->exists($v1->getFilename(), $v1->getHash(), 'boom'),
            'Variant of original file has been deleted'
        );
        $this->assertFalse(
            $store->exists($v2->getFilename(), $v2->getHash(), 'boom'),
            'Variant of original file does not get confused for a variant of the replaced file'
        );
        $this->assertTrue(
            $store->exists($v2->getFilename(), $v2->getHash()),
            'The replaced file exists'
        );

        // Let's create a variant for version 2
        $download->setFromString(
            'Variant of Documents/File.txt v2',
            $v2->getFilename(),
            $v2->getHash(),
            'boom'
        );

        // Let's create a third version with a variant
        $download->setFromString($v3Content, $v3->getFilename());
        $download->setFromString(
            'Variant of Documents/File.txt v3',
            $v3->getFilename(),
            $v3->getHash(),
            'boom'
        );
        $object1->write();
        $object1->publishSingle();
        $this->assertFalse(
            $store->exists($v2->getFilename(), $v2->getHash()),
            'Version 2 has been deleted'
        );
        $this->assertFalse(
            $store->exists($v2->getFilename(), $v2->getHash(), 'boom'),
            'Variant of version 2 has been deleted'
        );
        $this->assertTrue(
            $store->exists($v3->getFilename(), $v3->getHash(), 'boom'),
            'Variant of version 3 exists'
        );
        $this->assertEquals(
            'Variant of Documents/File.txt v3',
            $store->getAsString($v3->getFilename(), $v3->getHash(), 'boom'),
            'Variant of version 3 has the expected content'
        );
        $this->assertTrue(
            $store->exists($v3->getFilename(), $v3->getHash()),
            sprintf('Version 3 of %s exists', $v3->getFilename())
        );
    }

    /**
     * @return AssetStore
     */
    protected function getAssetStore()
    {
        return Injector::inst()->get(AssetStore::class);
    }
}

<?php

namespace SilverStripe\Assets\Tests\Storage;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Core\Validation\ValidationException;

class DBFileTest extends SapphireTest
{

    protected static $extra_dataobjects = [
        DBFileTest\TestObject::class,
        DBFileTest\Subclass::class,
    ];

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        // Set backend
        TestAssetStore::activate('DBFileTest');
        Director::config()->set('alternate_base_url', '/mysite/');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * Test that images in a DBFile are rendered properly
     */
    public function testRender()
    {
        $obj = new DBFileTest\TestObject();

        // Test image tag
        $fish = realpath(__DIR__ .'/../ImageTest/test-image-high-quality.jpg');
        $this->assertFileExists($fish);
        $obj->MyFile->setFromLocalFile($fish, 'awesome-fish.jpg');
        $this->assertEquals(
            '<img width="300" height="300" alt="awesome-fish.jpg" src="/mysite/assets/a870de278b/awesome-fish.jpg" loading="lazy" />',
            trim($obj->MyFile->forTemplate() ?? '')
        );

        // Test download tag
        $obj->MyFile->setFromString('puppies', 'subdir/puppy-document.txt');
        $this->assertStringContainsString(
            '<a href="/mysite/assets/subdir/2a17a9cb4b/puppy-document.txt" title="puppy-document.txt" download="puppy-document.txt">',
            trim($obj->MyFile->forTemplate() ?? '')
        );
    }

    public function testValidation()
    {
        $obj = new DBFileTest\ImageOnly();

        // Test from image
        $fish = realpath(__DIR__ .'/../ImageTest/test-image-high-quality.jpg');
        $this->assertFileExists($fish);
        $obj->MyFile->setFromLocalFile($fish, 'awesome-fish.jpg');

        // This should fail
        $this->expectException(ValidationException::class);
        $obj->MyFile->setFromString('puppies', 'subdir/puppy-document.txt');
    }

    public function testPermission()
    {
        $obj = new DBFileTest\TestObject();

        // Test from image
        $fish = realpath(__DIR__ .'/../ImageTest/test-image-high-quality.jpg');
        $this->assertFileExists($fish);
        $obj->MyFile->setFromLocalFile(
            $fish,
            'private/awesome-fish.jpg',
            null,
            null,
            [
            'visibility' => AssetStore::VISIBILITY_PROTECTED
            ]
        );

        // Test various file permissions work on DBFile
        $this->assertFalse($obj->MyFile->canViewFile());
        $obj->MyFile->getURL();
        $this->assertTrue($obj->MyFile->canViewFile());
        $obj->MyFile->revokeFile();
        $this->assertFalse($obj->MyFile->canViewFile());
        $obj->MyFile->getURL(false);
        $this->assertFalse($obj->MyFile->canViewFile());
        $obj->MyFile->grantFile();
        $this->assertTrue($obj->MyFile->canViewFile());
    }
}

<?php

namespace SilverStripe\Assets\Tests\Storage;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Tests\Storage\DBFileTest\TestFileWithPermissions;
use SilverStripe\Control\Director;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\ValidationException;

class DBFileTest extends SapphireTest
{

    protected static $extra_dataobjects = [
        DBFileTest\TestObject::class,
        DBFileTest\Subclass::class,
        DBFileTest\TestFileWithPermissions::class,
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

    public static function providePermissionWithDataObject(): array
    {
        return [
            [
                'visibility' => AssetStore::VISIBILITY_PROTECTED,
            ],
            [
                'visibility' => AssetStore::VISIBILITY_PUBLIC,
            ],
        ];
    }

    /**
     * @dataProvider providePermissionWithDataObject
     */
    public function testPermissionWithDataObject(string $visibility): void
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
            ['visibility' => $visibility]
        );
        $dbFile = $obj->MyFile;

        if ($visibility === AssetStore::VISIBILITY_PUBLIC) {
            // Public files can always be viewed
            $this->assertTrue($dbFile->canViewFile());
        } else {
            try {
                // No grant initially - and since there's no `File` object backing this
                // there's no canView permissions to fallback to - so no access
                $this->assertFalse($dbFile->canViewFile());
                // Explicitly no grant, so no access
                $dbFile->getURL(false);
                $this->assertFalse($dbFile->canViewFile());
                // Implicitly no grant, so no access
                $dbFile->getURL();
                $this->assertFalse($dbFile->canViewFile());
                // An explicit grant allows access
                $dbFile->getURL(true);
                $this->assertTrue($dbFile->canViewFile());
                // Revoking the grant denies access again
                $dbFile->revokeFile();
                $this->assertFalse($dbFile->canViewFile());
                // Explicitly granting access allows access
                $dbFile->grantFile();
                $this->assertTrue($dbFile->canViewFile());
            } finally {
                // Make sure we always revoke at the end so the next scenario starts fresh
                $dbFile->revokeFile();
            }
        }
    }

    public static function providePermissionWithFile(): array
    {
        $scenarios = [
            'public canview=true, published' => [
                'visibility' => AssetStore::VISIBILITY_PUBLIC,
                'published' => true,
                'loggedIn' => true,
                'canView' => true,
            ],
            'public canview=true, draft' => [
                'visibility' => AssetStore::VISIBILITY_PUBLIC,
                'published' => false,
                'loggedIn' => true,
                'canView' => true,
            ],
            'public canview=false, published' => [
                'visibility' => AssetStore::VISIBILITY_PUBLIC,
                'published' => true,
                'loggedIn' => true,
                'canView' => false,
            ],
            'public canview=false, draft' => [
                'visibility' => AssetStore::VISIBILITY_PUBLIC,
                'published' => false,
                'loggedIn' => true,
                'canView' => false,
            ],
            'protected canview=true, published' => [
                'visibility' => AssetStore::VISIBILITY_PROTECTED,
                'published' => true,
                'loggedIn' => true,
                'canView' => true,
            ],
            'protected canview=true, draft' => [
                'visibility' => AssetStore::VISIBILITY_PROTECTED,
                'published' => false,
                'loggedIn' => true,
                'canView' => true,
            ],
            'protected canview=false, published' => [
                'visibility' => AssetStore::VISIBILITY_PROTECTED,
                'published' => true,
                'loggedIn' => true,
                'canView' => false,
            ],
            'protected canview=false, draft' => [
                'visibility' => AssetStore::VISIBILITY_PROTECTED,
                'published' => false,
                'loggedIn' => true,
                'canView' => false,
            ],
        ];

        foreach ($scenarios as $name => $scenario) {
            $scenario['loggedIn'] = false;
            $scenarios[$name . ', anonymous'] = $scenario;
        }

        return $scenarios;
    }

    /**
     * @dataProvider providePermissionWithFile
     */
    public function testPermissionWithFile(string $visibility, bool $published, bool $loggedIn, bool $canView): void
    {
        // We have admin access by default
        if (!$loggedIn) {
            $this->logOut();
        }
        // Set up the file
        $file = new DBFileTest\TestFileWithPermissions();
        $fish = realpath(__DIR__ .'/../ImageTest/test-image-high-quality.jpg');
        $file->File->setFromLocalFile(
            $fish,
            'private/awesome-fish.jpg',
            null,
            null,
            ['visibility' => $visibility]
        );
        $file->write();
        if ($published) {
            $file->publishSingle();
        }
        $dbFile = $file->File;
        // Set permissions last so they don't affect visibility
        // Normally permissions (usually inherited permissions e.g. CanViewType) affect visibility
        // but that gets complicated and is out of scope for DBFile testing.
        TestFileWithPermissions::$canView = $canView;

        // Note that the File class manipulates the visibility based on version status.
        // This test simplifies things by skiping permission checks for visibility changes,
        // so basically all published files are public and all draft files are protected.
        if ($published) {
            // Public files are always viewable (skipping canView checks).
            $this->assertTrue($dbFile->canViewFile());
        } elseif (!$loggedIn) {
            // Draft files can never be viewed by anonymous users.
            $this->assertFalse($dbFile->canViewFile());
        } else {
            try {
                // No grant initially - fallback on canView check on the File object backing it
                $this->assertSame($canView, $dbFile->canViewFile());
                // Explicitly no grant, fallback on canView check
                $dbFile->getURL(false);
                $this->assertSame($canView, $dbFile->canViewFile());
                // Implicitly no grant, fallback on canView check
                $dbFile->getURL();
                $this->assertSame($canView, $dbFile->canViewFile());
                // An explicit grant allows access, bypassing canView check
                $dbFile->getURL(true);
                $this->assertTrue($dbFile->canViewFile());
                // Revoking the grant, fallback on canView check
                $dbFile->revokeFile();
                $this->assertSame($canView, $dbFile->canViewFile());
                // Explicitly granting access allows access, bypassing canView check
                $dbFile->grantFile();
                $this->assertTrue($dbFile->canViewFile());
            } finally {
                // Make sure we always revoke at the end so the next scenario starts fresh
                $dbFile->revokeFile();
            }
        }
    }
}

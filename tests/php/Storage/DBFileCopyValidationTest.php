<?php

namespace SilverStripe\Assets\Tests\Storage;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;

class DBFileCopyValidationTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        DBFileTest\ImageOnly::class,
    ];

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('DBFileCopyValidationTest');
        Director::config()->set('alternate_base_url', '/mysite/');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public static function provideDisallowedExtensionIsRejected(): array
    {
        return [
            'copy to disallowed extension' => [
                'method' => 'copyFile',
            ],
            'rename to disallowed extension' => [
                'method' => 'renameFile',
            ],
        ];
    }

    #[DataProvider('provideDisallowedExtensionIsRejected')]
    public function testDisallowedExtensionIsRejected(string $method): void
    {
        $obj = new DBFileTest\ImageOnly();
        $fish = realpath(__DIR__ . '/../ImageTest/test-image-high-quality.jpg');
        $this->assertFileExists($fish);
        $obj->MyFile->setFromLocalFile($fish, 'awesome-fish.jpg');
        $hash = $obj->MyFile->Hash;
        $store = Injector::inst()->get(AssetStore::class);
        try {
            $obj->MyFile->$method('awesome-fish.php');
        } catch (ValidationException) {
        }
        $this->assertFalse((bool) $store->exists('awesome-fish.php', $hash));
    }

    public static function provideAllowedExtensionIsAccepted(): array
    {
        return [
            'copy keeping allowed extension' => [
                'method' => 'copyFile',
            ],
            'rename keeping allowed extension' => [
                'method' => 'renameFile',
            ],
        ];
    }

    #[DataProvider('provideAllowedExtensionIsAccepted')]
    public function testAllowedExtensionIsAccepted(string $method): void
    {
        $obj = new DBFileTest\ImageOnly();
        $fish = realpath(__DIR__ . '/../ImageTest/test-image-high-quality.jpg');
        $this->assertFileExists($fish);
        $obj->MyFile->setFromLocalFile($fish, 'awesome-fish.jpg');
        $result = $obj->MyFile->$method('renamed-fish.jpg');
        $this->assertSame('renamed-fish.jpg', $result);
    }
}

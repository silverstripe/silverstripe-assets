<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Control\Director;
use SilverStripe\Dev\SapphireTest;
use PHPUnit\Framework\Attributes\DataProvider;

class PublicAssetAdapterTest extends SapphireTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Director::config()->set(
            'alternate_base_url',
            'http://www.mysitem.com/baseurl/'
        );
    }

    public function testInitBaseURL()
    {
        // Test windows paths generate correct url
        $base = ASSETS_PATH . '/subdir';
        $adapter = new PublicAssetAdapter($base);

        $this->assertEquals(
            '/baseurl/assets/subdir/dir/file.jpg',
            $adapter->getPublicUrl('dir/file.jpg')
        );
    }

    public static function provideGetPublicUrl(): array
    {
        return [
            'filename' => [
                'path' => 'lorem.jpg',
                'expected' => '/baseurl/assets/lorem.jpg',
            ],
            'unixPath' => [
                'path' => 'path/to/lorem.jpg',
                'expected' => '/baseurl/assets/path/to/lorem.jpg',
            ],
            'windowsPath' => [
                'path' => 'path\\to\\lorem.jpg',
                'expected' => '/baseurl/assets/path/to/lorem.jpg',
            ],
        ];
    }

    #[DataProvider('provideGetPublicUrl')]
    public function testGetPublicUrl(string $path, string $expected)
    {
        $adapter = new PublicAssetAdapter('assets');
        $actual = $adapter->getPublicUrl($path);
        $this->assertSame($expected, $actual);
    }
}

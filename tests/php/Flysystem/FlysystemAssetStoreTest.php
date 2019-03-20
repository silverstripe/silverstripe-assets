<?php

namespace SilverStripe\Assets\Tests\Flysystem;

use League\Flysystem\Filesystem;
use PHPUnit_Framework_MockObject_MockObject;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Flysystem\ProtectedAssetAdapter;
use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Dev\SapphireTest;

class FlysystemAssetStoreTest extends SapphireTest
{
    /**
     * @var PublicAssetAdapter
     */
    protected $publicAdapter;

    /**
     * @var Filesystem
     */
    protected $publicFilesystem;

    /**
     * @var ProtectedAssetAdapter
     */
    protected $protectedAdapter;

    /**
     * @var Filesystem
     */
    protected $protectedFilesystem;

    protected function setUp()
    {
        parent::setUp();

        $this->publicAdapter = $this->getMockBuilder(PublicAssetAdapter::class)
            ->setMethods(['getPublicUrl'])
            ->getMock();

        $this->publicFilesystem = $this->getMockBuilder(Filesystem::class)
            ->setMethods(['has'])
            ->setConstructorArgs([$this->publicAdapter])
            ->getMock();

        $this->protectedAdapter = $this->getMockBuilder(ProtectedAssetAdapter::class)
            ->setMethods(['getProtectedUrl'])
            ->getMock();

        $this->protectedFilesystem = $this->getMockBuilder(Filesystem::class)
            ->setMethods(['has'])
            ->setConstructorArgs([$this->protectedAdapter])
            ->getMock();
    }

    public function testGetAsUrlDoesntGrantForPublicAssets()
    {
        $this->publicFilesystem->expects($this->once())->method('has')->willReturn(true);
        $this->publicAdapter->expects($this->once())->method('getPublicUrl')->willReturn('public.jpg');
        $this->protectedFilesystem->expects($this->never())->method('has');

        $assetStore = new FlysystemAssetStore();

        $assetStore->setPublicFilesystem($this->publicFilesystem);
        $assetStore->setProtectedFilesystem($this->protectedFilesystem);

        $this->assertSame('public.jpg', $assetStore->getAsURL('foo', 'bar'));
    }

    /**
     * @param boolean $grant
     * @dataProvider protectedUrlGrantProvider
     */
    public function testGetAsUrlWithGrant($grant)
    {
        $this->publicFilesystem->expects($this->once())->method('has')->willReturn(false);
        $this->publicAdapter->expects($this->never())->method('getPublicUrl');
        $this->protectedFilesystem->expects($this->once())->method('has')->willReturn(true);
        $this->protectedAdapter->expects($this->once())->method('getProtectedUrl')->willReturn('protected.jpg');

        $assetStore = new FlysystemAssetStore();

        $assetStore->setPublicFilesystem($this->publicFilesystem);
        $assetStore->setProtectedFilesystem($this->protectedFilesystem);

        $this->assertSame('protected.jpg', $assetStore->getAsURL('foo', 'bar', 'baz', $grant));
    }

    /**
     * @return array[]
     */
    public function protectedUrlGrantProvider()
    {
        return [
            [true],
            [false],
        ];
    }
}

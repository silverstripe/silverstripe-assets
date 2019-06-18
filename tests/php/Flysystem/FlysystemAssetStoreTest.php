<?php

namespace SilverStripe\Assets\Tests\Flysystem;

use League\Flysystem\Filesystem;
use PHPUnit_Framework_MockObject_MockObject;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Flysystem\ProtectedAssetAdapter;
use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Assets\Tests\FilenameParsing\FileIDHelperResolutionStrategyTest;
use SilverStripe\Core\Injector\Injector;
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

    /** @var FileResolutionStrategy */
    protected $publicStrategy;

    /** @var FileResolutionStrategy */
    protected $protectedStrategy;

    protected function setUp()
    {
        parent::setUp();

        $this->publicAdapter = $this->getMockBuilder(PublicAssetAdapter::class)
            ->setMethods(['getPublicUrl'])
            ->getMock();

        $this->publicFilesystem = $this->getMockBuilder(Filesystem::class)
            ->setMethods(['has', 'read', 'readStream'])
            ->setConstructorArgs([$this->publicAdapter])
            ->getMock();

        $this->protectedAdapter = $this->getMockBuilder(ProtectedAssetAdapter::class)
            ->setMethods(['getProtectedUrl'])
            ->getMock();

        $this->protectedFilesystem = $this->getMockBuilder(Filesystem::class)
            ->setMethods(['has', 'read', 'readStream'])
            ->setConstructorArgs([$this->protectedAdapter])
            ->getMock();

        $this->publicStrategy = $this->getMockBuilder(FileIDHelperResolutionStrategy::class)
            ->setMethods(['searchForTuple'])
            ->getMock();

        $this->protectedStrategy = $this->getMockBuilder(FileIDHelperResolutionStrategy::class)
            ->setMethods(['searchForTuple'])
            ->getMock();
    }

    public function testGetAsUrlDoesntGrantForPublicAssets()
    {
        $this->publicFilesystem->expects($this->atLeastOnce())->method('has')->willReturn(true);
        $this->publicFilesystem
            ->expects($this->atLeastOnce())
            ->method('readStream')
            ->willReturn(fopen('data://text/plain,some dummy content', 'r'));
        $this->publicAdapter->expects($this->atLeastOnce())->method('getPublicUrl')->willReturn('public.jpg');
        $this->protectedFilesystem->expects($this->never())->method('has');

        $injector = Injector::inst();

        $assetStore = new FlysystemAssetStore();
        $assetStore->setPublicFilesystem($this->publicFilesystem);
        $assetStore->setProtectedFilesystem($this->protectedFilesystem);
        $assetStore->setPublicResolutionStrategy($injector->get(FileResolutionStrategy::class . '.public'));
        $assetStore->setProtectedResolutionStrategy($injector->get(FileResolutionStrategy::class . '.protected'));

        $this->assertSame('public.jpg', $assetStore->getAsURL('foo', sha1('some dummy content')));
    }

    /**
     * @param boolean $grant
     * @dataProvider protectedUrlGrantProvider
     */
    public function testGetAsUrlWithGrant($grant)
    {
        $this->publicFilesystem->expects($this->atLeastOnce())->method('has')->willReturn(false);
        $this->publicAdapter->expects($this->never())->method('getPublicUrl');
        $this->protectedFilesystem->expects($this->atLeastOnce())->method('has')->willReturn(true);
        $this->protectedAdapter->expects($this->atLeastOnce())->method('getProtectedUrl')->willReturn('protected.jpg');
        $this->protectedFilesystem->expects($this->atLeastOnce())
            ->method('readStream')
            ->willReturn(fopen('data://text/plain,some dummy content', 'r'));

        $injector = Injector::inst();

        $assetStore = new FlysystemAssetStore();
        $assetStore->setPublicFilesystem($this->publicFilesystem);
        $assetStore->setProtectedFilesystem($this->protectedFilesystem);
        $assetStore->setPublicResolutionStrategy($injector->get(FileResolutionStrategy::class . '.public'));
        $assetStore->setProtectedResolutionStrategy($injector->get(FileResolutionStrategy::class . '.protected'));

        $this->assertSame('protected.jpg', $assetStore->getAsURL('foo', sha1('some dummy content'), 'baz', $grant));
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

    public function testPublicFilesystem()
    {
        $assetStore = new FlysystemAssetStore();
        $assetStore->setPublicFilesystem($this->publicFilesystem);
        $this->assertEquals($this->publicFilesystem, $assetStore->getPublicFilesystem());
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testBadPublicFilesystem()
    {
        $assetStore = new FlysystemAssetStore();
        $assetStore->setPublicFilesystem($this->protectedFilesystem);
    }

    public function testProtectedFilesystem()
    {
        $assetStore = new FlysystemAssetStore();
        $assetStore->setProtectedFilesystem($this->protectedFilesystem);
        $this->assertEquals($this->protectedFilesystem, $assetStore->getProtectedFilesystem());
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testBadProtectedFilesystem()
    {
        $assetStore = new FlysystemAssetStore();
        $assetStore->setProtectedFilesystem($this->publicFilesystem);
    }

    public function testPublicResolutionStrategy()
    {
        $assetStore = new FlysystemAssetStore();
        $strategy = $assetStore->getPublicResolutionStrategy();
        $expected = Injector::inst()->get(FileResolutionStrategy::class . '.public');
        $this->assertEquals($expected, $strategy);

        $expected = FileIDHelperResolutionStrategy::create();
        $assetStore->setPublicResolutionStrategy($expected);
        $strategy = $assetStore->getPublicResolutionStrategy();
        $this->assertEquals($expected, $strategy);
    }

    public function testProtectedResolutionStrategy()
    {
        $assetStore = new FlysystemAssetStore();
        $strategy = $assetStore->getProtectedResolutionStrategy();
        $expected = Injector::inst()->get(FileResolutionStrategy::class . '.protected');
        $this->assertEquals($expected, $strategy);

        $expected = FileIDHelperResolutionStrategy::create();
        $assetStore->setProtectedResolutionStrategy($expected);
        $strategy = $assetStore->getProtectedResolutionStrategy();
        $this->assertEquals($expected, $strategy);
    }
}

<?php

namespace SilverStripe\Assets\Tests\Flysystem;

use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\Flysystem\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Flysystem\ProtectedAssetAdapter;
use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Assets\Tests\FilenameParsing\FileIDHelperResolutionStrategyTest;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use PHPUnit\Framework\Attributes\DataProvider;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicAdapter = $this->getMockBuilder(PublicAssetAdapter::class)
            ->onlyMethods(['getPublicUrl'])
            ->getMock();

        $this->publicFilesystem = $this->getMockBuilder(Filesystem::class)
            ->onlyMethods(['has', 'read', 'readStream', 'lastModified'])
            ->setConstructorArgs([$this->publicAdapter])
            ->getMock();

        $this->protectedAdapter = $this->getMockBuilder(ProtectedAssetAdapter::class)
            ->onlyMethods(['getProtectedUrl'])
            ->getMock();

        $this->protectedFilesystem = $this->getMockBuilder(Filesystem::class)
            ->onlyMethods(['has', 'read', 'readStream', 'lastModified'])
            ->setConstructorArgs([$this->protectedAdapter])
            ->getMock();

        $this->publicStrategy = $this->getMockBuilder(FileIDHelperResolutionStrategy::class)
            ->onlyMethods(['searchForTuple'])
            ->getMock();

        $this->protectedStrategy = $this->getMockBuilder(FileIDHelperResolutionStrategy::class)
            ->onlyMethods(['searchForTuple'])
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
     */
    #[DataProvider('protectedUrlGrantProvider')]
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
    public static function protectedUrlGrantProvider()
    {
        return [
            [true],
            [false],
        ];
    }

    /**
     * Check that `getAsURL()` returns the link of a protected file when there is no controller to hold the grant
     */
    public function testGetAsUrlWithGrantWithoutController()
    {
        $this->publicFilesystem->method('has')->willReturn(false);
        $this->protectedFilesystem->method('has')->willReturn(true);
        $this->protectedAdapter->method('getProtectedUrl')->willReturn('protected.jpg');
        $this->protectedFilesystem
            ->method('readStream')
            ->willReturnCallback(fn() => fopen('data://text/plain,some dummy content', 'r'));
        $assetStore = $this->createAssetStore();
        $hash = sha1('some dummy content');

        $url = $this->withoutController(fn() => $assetStore->getAsURL('foo', $hash, null, true));

        $this->assertSame('protected.jpg', $url);
    }

    /**
     * Check that `revoke()` and `canView()` work on a protected file when there is no controller to hold a grant
     */
    public function testRevokeAndCanViewWithoutController()
    {
        $this->publicFilesystem->method('has')->willReturn(false);
        $this->protectedFilesystem->method('has')->willReturn(true);
        $this->protectedFilesystem
            ->method('readStream')
            ->willReturnCallback(fn() => fopen('data://text/plain,some dummy content', 'r'));
        $assetStore = $this->createAssetStore();
        $hash = sha1('some dummy content');

        $canView = $this->withoutController(function () use ($assetStore, $hash) {
            $assetStore->revoke('foo', $hash);
            return $assetStore->canView('foo', $hash);
        });

        $this->assertFalse($canView);
    }

    /**
     * Create an asset store that uses the mocked filesystems
     */
    private function createAssetStore(): FlysystemAssetStore
    {
        $injector = Injector::inst();
        $assetStore = new FlysystemAssetStore();
        $assetStore->setPublicFilesystem($this->publicFilesystem);
        $assetStore->setProtectedFilesystem($this->protectedFilesystem);
        $assetStore->setPublicResolutionStrategy($injector->get(FileResolutionStrategy::class . '.public'));
        $assetStore->setProtectedResolutionStrategy($injector->get(FileResolutionStrategy::class . '.protected'));
        return $assetStore;
    }

    /**
     * Run a callback with the controller stack emptied, as it is for a command-line process
     */
    private function withoutController(callable $callback): mixed
    {
        $controllers = [];
        while ($controller = Controller::curr()) {
            $controllers[] = $controller;
            $controller->popCurrent();
        }
        try {
            return $callback();
        } finally {
            foreach (array_reverse($controllers) as $controller) {
                $controller->pushCurrent();
            }
        }
    }

    public function testPublicFilesystem()
    {
        $assetStore = new FlysystemAssetStore();
        $assetStore->setPublicFilesystem($this->publicFilesystem);
        $this->assertEquals($this->publicFilesystem, $assetStore->getPublicFilesystem());
    }

    public function testBadPublicFilesystem()
    {
        $this->expectException(\InvalidArgumentException::class);
        $assetStore = new FlysystemAssetStore();
        $assetStore->setPublicFilesystem($this->protectedFilesystem);
    }

    public function testProtectedFilesystem()
    {
        $assetStore = new FlysystemAssetStore();
        $assetStore->setProtectedFilesystem($this->protectedFilesystem);
        $this->assertEquals($this->protectedFilesystem, $assetStore->getProtectedFilesystem());
    }

    public function testBadProtectedFilesystem()
    {
        $this->expectException(\InvalidArgumentException::class);
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

<?php

namespace SilverStripe\Assets\Tests\Flysystem;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class FlysystemAssetStoreUpdateResponseTest extends SapphireTest
{
    protected static $fixture_file = 'FlysystemAssetStoreUpdateResponseTest.yml';

    protected static $required_extensions = [
        FlysystemAssetStore::class => [FlysystemAssetStoreExtension::class]
    ];

    private $hash;

    /**
     * @skipUpgrade
     */
    public function setUp()
    {
        parent::setUp();

        // Set backend and base url
        TestAssetStore::activate('FlysystemAssetStoreUpdateResponseTest');

        $store = $this->getBackend();

        /** @var FileIDHelperResolutionStrategy $strategy  */
        $strategy = $store->getPublicResolutionStrategy();
        $strategy->setResolutionFileIDHelpers([
            HashFileIDHelper::singleton(),
            NaturalFileIDHelper::singleton()
        ]);
        $strategy->setDefaultFileIDHelper(NaturalFileIDHelper::singleton());


        /** @var File $publicFile */
        $publicFile = $this->objFromFixture(File::class, 'public');
        $publicFile->setFromString(
            'hello',
            'public.txt'
        );
        $publicFile->publishSingle();

        /** @var File $protectedFile */
        $protectedFile = $this->objFromFixture(File::class, 'protected');
        $protectedFile->setFromString('hello', 'protected.txt');
        $protectedFile->publishSingle();
        $this->hash = substr(sha1('hello'), 0, 10);
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * @return TestAssetStore
     */
    protected function getBackend()
    {
        return Injector::inst()->get(AssetStore::class);
    }

    public function testPublicFile()
    {
        $store = $this->getBackend();
        $actualResponse = $store->getResponseFor('public.txt');

        [$expectedResponse, $asset, $context] = FlysystemAssetStoreExtension::$lastHookCall;

        $this->assertEquals($expectedResponse, $actualResponse);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->assertEquals('public.txt', $asset);
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $context['visibility']);

        $this->assertEquals(
            FlysystemAssetStoreExtension::$callCount,
            $actualResponse->getHeader('FlysystemAssetStoreExtensionCallCount')
        );
    }

    public function testProtectedFile()
    {
        $this->logOut();
        $store = $this->getBackend();
        $expectedAsset = $this->hash . '/protected.txt';

        $actualResponse = $store->getResponseFor($expectedAsset);
        [$expectedResponse, $asset, $context] = FlysystemAssetStoreExtension::$lastHookCall;

        $this->assertEquals($expectedResponse, $actualResponse);
        $this->assertEquals(403, $actualResponse->getStatusCode());
        $this->assertEquals($expectedAsset, $asset);
        $this->assertEquals(AssetStore::VISIBILITY_PROTECTED, $context['visibility']);
        $this->assertEquals(
            FlysystemAssetStoreExtension::$callCount,
            $actualResponse->getHeader('FlysystemAssetStoreExtensionCallCount')
        );

        $store->grant('protected.txt', $this->hash);

        $actualResponse = $store->getResponseFor($expectedAsset);
        [$expectedResponse, $asset, $context] = FlysystemAssetStoreExtension::$lastHookCall;

        $this->assertEquals($expectedResponse, $actualResponse);
        $this->assertEquals(200, $actualResponse->getStatusCode());
        $this->assertEquals($expectedAsset, $asset);
        $this->assertEquals(AssetStore::VISIBILITY_PROTECTED, $context['visibility']);
        $this->assertEquals(
            FlysystemAssetStoreExtension::$callCount,
            $actualResponse->getHeader('FlysystemAssetStoreExtensionCallCount')
        );
    }

    public function testPublicRedirectFile()
    {
        $store = $this->getBackend();
        $expectedAsset = $this->hash . '/public.txt';
        $actualResponse = $store->getResponseFor($expectedAsset);

        [$expectedResponse, $asset, $context] = FlysystemAssetStoreExtension::$lastHookCall;

        $this->assertEquals($expectedResponse, $actualResponse);
        $this->assertEquals(301, $actualResponse->getStatusCode());
        $this->assertEquals(
            '/assets/FlysystemAssetStoreUpdateResponseTest/public.txt',
            $actualResponse->getHeader('Location')
        );
        $this->assertEquals($expectedAsset, $asset);
        $this->assertEquals(AssetStore::VISIBILITY_PUBLIC, $context['visibility']);

        $this->assertEquals(
            FlysystemAssetStoreExtension::$callCount,
            $actualResponse->getHeader('FlysystemAssetStoreExtensionCallCount')
        );
    }

    public function testMissingFile()
    {
        $store = $this->getBackend();
        $expectedAsset = '/four-o-four.txt';
        $actualResponse = $store->getResponseFor($expectedAsset);

        [$expectedResponse, $asset, $context] = FlysystemAssetStoreExtension::$lastHookCall;

        $this->assertEquals($expectedResponse, $actualResponse);
        $this->assertEquals(404, $actualResponse->getStatusCode());
        $this->assertEquals($expectedAsset, $asset);
        $this->assertEmpty($context);

        $this->assertEquals(
            FlysystemAssetStoreExtension::$callCount,
            $actualResponse->getHeader('FlysystemAssetStoreExtensionCallCount')
        );
    }
}

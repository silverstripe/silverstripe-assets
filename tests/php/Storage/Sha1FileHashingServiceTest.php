<?php

namespace SilverStripe\Assets\Tests\Storage;

use Exception;
use InvalidArgumentException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
use ReflectionMethod;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Assets\Storage\Sha1FileHashingService;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use Symfony\Component\Cache\CacheItem;

class Sha1FileHashingServiceTest extends SapphireTest
{

    /** @var Filesystem */
    private $publicFs;

    /** @var Filesystem */
    private $protectedFs;

    private $publicHash;
    private $protectedHash;

    private $publicContent = 'The quick brown fox jumps over the lazy dog';
    private $protectedContent = 'Pack my box with five dozen liquor jugs';

    private $fileID = 'Sha1FileHashingServiceTest/Pangram.txt';

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicHash = sha1($this->publicContent);
        $this->protectedHash = sha1($this->protectedContent);

        $this->publicFs = Injector::inst()->get(
            sprintf('%s.%s', Filesystem::class, AssetStore::VISIBILITY_PUBLIC)
        );
        $this->protectedFs = Injector::inst()->get(
            sprintf('%s.%s', Filesystem::class, AssetStore::VISIBILITY_PROTECTED)
        );

        $this->publicFs->write($this->fileID, $this->publicContent);
        $this->protectedFs->write($this->fileID, $this->protectedContent);
        Sha1FileHashingService::flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->publicFs->deleteDir('Sha1FileHashingServiceTest');
        $this->protectedFs->deleteDir('Sha1FileHashingServiceTest');
    }

    public function testComputeFromStream()
    {
        $service = new Sha1FileHashingService();
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, $this->publicContent);
            $hash = $service->computeFromStream($stream);
            $this->assertEquals($this->publicHash, $hash);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testComputeFromFile()
    {
        $service = new Sha1FileHashingService();
        $service->disableCache();

        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, $this->publicFs));
        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertEquals($this->protectedHash, $service->computeFromFile($this->fileID, $this->protectedFs));
        $this->assertEquals($this->protectedHash, $service->computeFromFile($this->fileID, AssetStore::VISIBILITY_PROTECTED));

        // Cache is disable so this bit should be ignored
        $service->set($this->fileID, AssetStore::VISIBILITY_PUBLIC, $this->protectedHash);
        $service->set($this->fileID, $this->protectedFs, $this->publicHash);

        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, $this->publicFs));
        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertEquals($this->protectedHash, $service->computeFromFile($this->fileID, $this->protectedFs));
        $this->assertEquals($this->protectedHash, $service->computeFromFile($this->fileID, AssetStore::VISIBILITY_PROTECTED));
    }

    public function testComputeMissingFile()
    {
        $this->expectException(FileNotFoundException::class);
        $service = new Sha1FileHashingService();
        $service->computeFromFile('missing-file.text', AssetStore::VISIBILITY_PROTECTED);
    }

    public function testComputeBadFilesystem()
    {
        $this->expectException(\InvalidArgumentException::class);
        $service = new Sha1FileHashingService();
        $service->computeFromFile($this->fileID, AssetStore::CONFLICT_OVERWRITE);
    }

    public function testComputeWithCache()
    {
        $service = new Sha1FileHashingService();
        $service->enableCache();

        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, $this->publicFs));
        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertEquals($this->protectedHash, $service->computeFromFile($this->fileID, $this->protectedFs));
        $this->assertEquals($this->protectedHash, $service->computeFromFile($this->fileID, AssetStore::VISIBILITY_PROTECTED));

        // Lie to the cache about the value of our hashes
        $service->set($this->fileID, AssetStore::VISIBILITY_PUBLIC, $this->protectedHash);
        $service->set($this->fileID, $this->protectedFs, $this->publicHash);

        $this->assertEquals($this->protectedHash, $service->computeFromFile($this->fileID, $this->publicFs));
        $this->assertEquals($this->protectedHash, $service->computeFromFile($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, $this->protectedFs));
        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, AssetStore::VISIBILITY_PROTECTED));

        // Lie about a missing file
        $hash = sha1('missing-file.text');
        $service->set('missing-file.text', AssetStore::VISIBILITY_PUBLIC, $hash);
        $this->assertEquals($hash, $service->computeFromFile('missing-file.text', AssetStore::VISIBILITY_PUBLIC));
    }

    public function testComputeTouchFile()
    {
        $service = new Sha1FileHashingService();
        $service->enableCache();

        $this->assertEquals($this->publicHash, $service->computeFromFile($this->fileID, $this->publicFs));

        // Our timestamp is accruate to the second, we need to wait a bit to make sure our new timestamp won't be in
        // the same second as the old one.
        sleep(2);
        $this->publicFs->update($this->fileID, $this->protectedContent);

        $this->assertEquals(
            $this->protectedHash,
            $service->computeFromFile($this->fileID, $this->publicFs),
            'When a file is touched by an outside process, existing value for that file are invalidated'
        );
    }

    public function testCompare()
    {
        $service = new Sha1FileHashingService();
        $partialHash = substr($this->protectedHash, 0, 10);

        $this->assertTrue($service->compare($this->protectedHash, $this->protectedHash));
        $this->assertTrue($service->compare($this->protectedHash, $partialHash));
        $this->assertTrue($service->compare($partialHash, $this->protectedHash));

        $this->assertFalse($service->compare($this->publicHash, $this->protectedHash));
        $this->assertFalse($service->compare($partialHash, $this->publicHash));
    }

    public function testCompareWithEmptyFirstHash()
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = new Sha1FileHashingService();
        $this->assertTrue($service->compare('', $this->protectedHash));
    }

    public function testCompareWithEmptySecondHash()
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = new Sha1FileHashingService();
        $this->assertTrue($service->compare($this->protectedHash, ''));
    }

    public function testIsCached()
    {
        $service = new Sha1FileHashingService();
        $this->assertTrue($service->isCached());

        $service->disableCache();
        $this->assertFalse($service->isCached());

        $service->enableCache();
        $this->assertTrue($service->isCached());
    }

    public function testInvalidate()
    {
        $service = new Sha1FileHashingService();
        $service->enableCache();

        // Lie to the cache about the value of our hashes
        $service->set($this->fileID, AssetStore::VISIBILITY_PUBLIC, $this->protectedHash);
        $service->set($this->fileID, $this->protectedFs, $this->publicHash);

        $service->invalidate($this->fileID, AssetStore::VISIBILITY_PUBLIC);
        $service->invalidate($this->fileID, $this->protectedFs);

        $this->assertFalse($service->get($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertFalse($service->get($this->fileID, $this->protectedFs));
    }

    public function testFlush()
    {
        $service = new Sha1FileHashingService();
        Injector::inst()->registerService($service, FileHashingService::class);


        $service->enableCache();

        // Lie to the cache about the value of our hashes
        $service->set($this->fileID, AssetStore::VISIBILITY_PUBLIC, $this->protectedHash);
        $service->set($this->fileID, $this->protectedFs, $this->publicHash);

        Sha1FileHashingService::flush();

        $this->assertFalse($service->get($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertFalse($service->get($this->fileID, $this->protectedFs));
    }

    public function testMove()
    {
        $service = new Sha1FileHashingService();
        $service->enableCache();

        $service->set($this->fileID, AssetStore::VISIBILITY_PUBLIC, $this->protectedHash);

        $service->move($this->fileID, AssetStore::VISIBILITY_PUBLIC, $this->fileID, $this->protectedFs);

        $this->assertEquals($this->protectedHash, $service->get($this->fileID, AssetStore::VISIBILITY_PROTECTED));
        $this->assertFalse($service->get($this->fileID, $this->publicFs));

        $service->move($this->fileID, $this->protectedFs, 'different-file.txt');
        $this->assertFalse($service->get($this->fileID, AssetStore::VISIBILITY_PROTECTED));
        $this->assertEquals($this->protectedHash, $service->get('different-file.txt', $this->protectedFs));
    }

    public function testInvalidCharacterCacheKey()
    {
        $service = new Sha1FileHashingService();
        // We're using reflection here because the method is private
        $reflectedMethod = new ReflectionMethod(
            Sha1FileHashingService::class,
            'buildCacheKey'
        );
        $reflectedMethod->setAccessible(true);
        // We're using this filename here as it was caught in issue #426
        $cacheKey = $reflectedMethod->invokeArgs(
            $service,
            ['Mit_Teamgeist_zum_großen_Paddelerlebnis.pdf', 'file-system']
        );

        $this->assertStringNotContainsString('/', $cacheKey);
        // Ensure we get a string back after validating the key and it is therefore valid
        $this->assertIsString(CacheItem::validateKey($cacheKey));
    }
}

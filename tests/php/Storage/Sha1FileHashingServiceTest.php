<?php

namespace SilverStripe\Assets\Tests\Storage;

use Exception;
use InvalidArgumentException;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Filesystem;
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

    public function setUp()
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
    }

    public function tearDown()
    {
        parent::tearDown();
        $this->publicFs->deleteDir('Sha1FileHashingServiceTest');
        $this->protectedFs->deleteDir('Sha1FileHashingServiceTest');
    }

    public function testComputeStream()
    {
        $service = new Sha1FileHashingService();
        $stream = fopen('php://temp', 'r+');
        try {
            fwrite($stream, $this->publicContent);
            $hash = $service->computeStream($stream);
            $this->assertEquals($this->publicHash, $hash);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function testCompute()
    {
        $service = new Sha1FileHashingService();
        $service->disableCache();

        $this->assertEquals($this->publicHash, $service->compute($this->fileID, $this->publicFs));
        $this->assertEquals($this->publicHash, $service->compute($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertEquals($this->protectedHash, $service->compute($this->fileID, $this->protectedFs));
        $this->assertEquals($this->protectedHash, $service->compute($this->fileID, AssetStore::VISIBILITY_PROTECTED));

        // Cache is disable so this bit should be ignored
        $service->set($this->fileID, AssetStore::VISIBILITY_PUBLIC, $this->protectedHash);
        $service->set($this->fileID, $this->protectedFs, $this->publicHash);

        $this->assertEquals($this->publicHash, $service->compute($this->fileID, $this->publicFs));
        $this->assertEquals($this->publicHash, $service->compute($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertEquals($this->protectedHash, $service->compute($this->fileID, $this->protectedFs));
        $this->assertEquals($this->protectedHash, $service->compute($this->fileID, AssetStore::VISIBILITY_PROTECTED));
    }

    public function testComputeMissingFile()
    {
        $this->expectException(FileNotFoundException::class);
        $service = new Sha1FileHashingService();
        $service->compute('missing-file.text', AssetStore::VISIBILITY_PROTECTED);
    }

    public function testComputeBadFilesystem()
    {
        $this->expectException(InvalidArgumentException::class);
        $service = new Sha1FileHashingService();
        $service->compute($this->fileID, AssetStore::CONFLICT_OVERWRITE);
    }

    public function testComputeWithCache()
    {
        $service = new Sha1FileHashingService();
        $service->enableCache();

        $this->assertEquals($this->publicHash, $service->compute($this->fileID, $this->publicFs));
        $this->assertEquals($this->publicHash, $service->compute($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertEquals($this->protectedHash, $service->compute($this->fileID, $this->protectedFs));
        $this->assertEquals($this->protectedHash, $service->compute($this->fileID, AssetStore::VISIBILITY_PROTECTED));

        // Lie to the cache about the value of our hashes
        $service->set($this->fileID, AssetStore::VISIBILITY_PUBLIC, $this->protectedHash);
        $service->set($this->fileID, $this->protectedFs, $this->publicHash);

        $this->assertEquals($this->protectedHash, $service->compute($this->fileID, $this->publicFs));
        $this->assertEquals($this->protectedHash, $service->compute($this->fileID, AssetStore::VISIBILITY_PUBLIC));
        $this->assertEquals($this->publicHash, $service->compute($this->fileID, $this->protectedFs));
        $this->assertEquals($this->publicHash, $service->compute($this->fileID, AssetStore::VISIBILITY_PROTECTED));

        // Lie about a missing file
        $hash = sha1('missing-file.text');
        $service->set('missing-file.text', AssetStore::VISIBILITY_PUBLIC, $hash);
        $this->assertEquals($hash, $service->compute('missing-file.text', AssetStore::VISIBILITY_PUBLIC));
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
        $this->expectException(InvalidArgumentException::class);

        $service = new Sha1FileHashingService();
        $this->assertTrue($service->compare('', $this->protectedHash));
    }

    public function testCompareWithEmptySecondHash()
    {
        $this->expectException(InvalidArgumentException::class);

        $service = new Sha1FileHashingService();
        $this->assertTrue($service->compare($this->protectedHash, ''));
    }

    public function testIsCached()
    {
        $service = new Sha1FileHashingService();
        $this->assertFalse($service->isCached());

        $service->enableCache();
        $this->assertTrue($service->isCached());

        $service->disableCache();
        $this->assertFalse($service->isCached());
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
}

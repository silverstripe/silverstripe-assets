<?php

namespace SilverStripe\Assets\Tests;

use InvalidArgumentException;
use Prophecy\Prophecy\ObjectProphecy;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Assets\Tests\Storage\AssetStoreTest\TestAssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * ImageTest is abstract and should be overridden with manipulator-specific subtests
 * @skipUpgrade
 */
abstract class ImageTest extends SapphireTest
{
    protected static $fixture_file = 'ImageTest.yml';

    public function setUp()
    {
        parent::setUp();

        // Set backend root to /ImageTest
        TestAssetStore::activate('ImageTest');

        // Copy test images for each of the fixture references
        /** @var File $image */
        $files = File::get()->exclude('ClassName', Folder::class);
        foreach ($files as $image) {
            $sourcePath = __DIR__ . '/ImageTest/' . $image->Name;
            $image->setFromLocalFile($sourcePath, $image->Filename);
        }

        // Set default config
        InterventionBackend::config()->set('error_cache_ttl', [
            InterventionBackend::FAILED_INVALID => 0,
            InterventionBackend::FAILED_MISSING => '5,10',
            InterventionBackend::FAILED_UNKNOWN => 300,
        ]);
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testGetTagWithTitle()
    {
        Config::modify()->set(DBFile::class, 'force_resample', false);

        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $expected = '<img src="/assets/ImageTest/folder/444065542b/test-image.png" alt="This is a image Title" />';
        $actual = trim($image->getTag());

        $this->assertEquals($expected, $actual);
    }

    public function testAutoOrientateOnEXIFData()
    {
        $image = $this->objFromFixture(Image::class, 'exifPortrait');
        $this->assertEquals(Image_Backend::ORIENTATION_PORTRAIT, $image->getOrientation());
        $resampled = $image->Resampled();
        $this->assertEquals(Image_Backend::ORIENTATION_PORTRAIT, $resampled->getOrientation());
    }

    public function testExifOrientationOnManipulatedImage()
    {
        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'exifPortrait');
        $resampled = $image->ScaleHeight(100);
        $this->assertEquals(Image_Backend::ORIENTATION_PORTRAIT, $resampled->getOrientation());
        $this->assertEquals(100, $resampled->getHeight());
    }

    public function testGetTagWithoutTitle()
    {
        Config::modify()->set(DBFile::class, 'force_resample', false);

        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $expected = '<img src="/assets/ImageTest/folder/444065542b/test-image.png" alt="test image" />';
        $actual = trim($image->getTag());

        $this->assertEquals($expected, $actual);
    }

    public function testGetTagWithoutTitleContainingDots()
    {
        Config::modify()->set(DBFile::class, 'force_resample', false);

        $image = $this->objFromFixture(Image::class, 'imageWithoutTitleContainingDots');
        $expected = '<img src="/assets/ImageTest/folder/46affab704/test.image.with.dots.png" alt="test.image.with.dots" />';
        $actual = trim($image->getTag());

        $this->assertEquals($expected, $actual);
    }

    /**
     * Tests that multiple image manipulations may be performed on a single Image
     */
    public function testMultipleGenerateManipulationCalls()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');

        $imageFirst = $image->ScaleWidth(200);
        $this->assertNotNull($imageFirst);
        $expected = 200;
        $actual = $imageFirst->getWidth();

        $this->assertEquals($expected, $actual);

        $imageSecond = $imageFirst->ScaleHeight(100);
        $this->assertNotNull($imageSecond);
        $expected = 100;
        $actual = $imageSecond->getHeight();
        $this->assertEquals($expected, $actual);
    }

    /**
     * Tests that image manipulations that do not affect the resulting dimensions
     * of the output image do not resample the file.
     */
    public function testReluctanceToResampling()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $this->assertTrue($image->isSize(300, 300));

        // Set width to 300 pixels
        $imageScaleWidth = $image->ScaleWidth(300);
        $this->assertEquals($imageScaleWidth->getWidth(), 300);
        $this->assertEquals($image->Filename, $imageScaleWidth->Filename);

        // Set height to 300 pixels
        $imageScaleHeight = $image->ScaleHeight(300);
        $this->assertEquals($imageScaleHeight->getHeight(), 300);
        $this->assertEquals($image->Filename, $imageScaleHeight->Filename);

        // Crop image to 300 x 300
        $imageCropped = $image->Fill(300, 300);
        $this->assertTrue($imageCropped->isSize(300, 300));
        $this->assertEquals($image->Filename, $imageCropped->Filename);

        // Resize (padded) to 300 x 300
        $imageSized = $image->Pad(300, 300);
        $this->assertTrue($imageSized->isSize(300, 300));
        $this->assertEquals($image->Filename, $imageSized->Filename);

        // Padded image 300 x 300 (same as above)
        $imagePadded = $image->Pad(300, 300);
        $this->assertTrue($imagePadded->isSize(300, 300));
        $this->assertEquals($image->Filename, $imagePadded->Filename);

        // Resized (stretched) to 300 x 300
        $imageStretched = $image->ResizedImage(300, 300);
        $this->assertTrue($imageStretched->isSize(300, 300));
        $this->assertEquals($image->Filename, $imageStretched->Filename);

        // Fit (various options)
        $imageFit = $image->Fit(300, 600);
        $this->assertTrue($imageFit->isSize(300, 300));
        $this->assertEquals($image->Filename, $imageFit->Filename);
        $imageFit = $image->Fit(600, 300);
        $this->assertTrue($imageFit->isSize(300, 300));
        $this->assertEquals($image->Filename, $imageFit->Filename);
        $imageFit = $image->Fit(300, 300);
        $this->assertTrue($imageFit->isSize(300, 300));
        $this->assertEquals($image->Filename, $imageFit->Filename);
    }

    /**
     * Tests that a URL to a resampled image is provided when force_resample is
     * set to true, if the resampled file is smaller than the original.
     */
    public function testForceResample()
    {
        $imageHQ = $this->objFromFixture(Image::class, 'highQualityJPEG');
        $imageHQR = $imageHQ->Resampled();
        $imageLQ = $this->objFromFixture(Image::class, 'lowQualityJPEG');
        $imageLQR = $imageLQ->Resampled();

        // Test resampled file is served when force_resample = true
        Config::modify()->set(DBFile::class, 'force_resample', true);
        $this->assertLessThan($imageHQ->getAbsoluteSize(), $imageHQR->getAbsoluteSize(), 'Resampled image is smaller than original');
        $this->assertEquals($imageHQ->getURL(), $imageHQR->getSourceURL(), 'Path to a resampled image was returned by getURL()');

        // Test original file is served when force_resample = true but original file is low quality
        $this->assertGreaterThanOrEqual($imageLQ->getAbsoluteSize(), $imageLQR->getAbsoluteSize(), 'Resampled image is larger or same size as original');
        $this->assertNotEquals($imageLQ->getURL(), $imageLQR->getSourceURL(), 'Path to the original image file was returned by getURL()');

        // Test original file is served when force_resample = false
        Config::modify()->set(DBFile::class, 'force_resample', false);
        $this->assertNotEquals($imageHQ->getURL(), $imageHQR->getSourceURL(), 'Path to the original image file was returned by getURL()');
    }

    public function testImageResize()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $this->assertTrue($image->isSize(300, 300));

        // Test normal resize
        $resized = $image->Pad(150, 100);
        $this->assertTrue($resized->isSize(150, 100));

        // Test cropped resize
        $cropped = $image->Fill(100, 200);
        $this->assertTrue($cropped->isSize(100, 200));

        // Test padded resize
        $padded = $image->Pad(200, 100);
        $this->assertTrue($padded->isSize(200, 100));

        // Test Fit
        $ratio = $image->Fit(80, 160);
        $this->assertTrue($ratio->isSize(80, 80));

        // Test FitMax
        $fitMaxDn = $image->FitMax(200, 100);
        $this->assertTrue($fitMaxDn->isSize(100, 100));
        $fitMaxUp = $image->FitMax(500, 400);
        $this->assertTrue($fitMaxUp->isSize(300, 300));

        //Test ScaleMax
        $scaleMaxWDn = $image->ScaleMaxWidth(200);
        $this->assertTrue($scaleMaxWDn->isSize(200, 200));
        $scaleMaxWUp = $image->ScaleMaxWidth(400);
        $this->assertTrue($scaleMaxWUp->isSize(300, 300));
        $scaleMaxHDn = $image->ScaleMaxHeight(200);
        $this->assertTrue($scaleMaxHDn->isSize(200, 200));
        $scaleMaxHUp = $image->ScaleMaxHeight(400);
        $this->assertTrue($scaleMaxHUp->isSize(300, 300));

        // Test FillMax
        $cropMaxDn = $image->FillMax(200, 100);
        $this->assertTrue($cropMaxDn->isSize(200, 100));
        $cropMaxUp = $image->FillMax(400, 200);
        $this->assertTrue($cropMaxUp->isSize(300, 150));

        // Test Clip
        $clipWDn = $image->CropWidth(200);
        $this->assertTrue($clipWDn->isSize(200, 300));
        $clipWUp = $image->CropWidth(400);
        $this->assertTrue($clipWUp->isSize(300, 300));
        $clipHDn = $image->CropHeight(200);
        $this->assertTrue($clipHDn->isSize(300, 200));
        $clipHUp = $image->CropHeight(400);
        $this->assertTrue($clipHUp->isSize(300, 300));
    }

    /**
     * Test input arg validation
     */
    public function testImageResizeValidate()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $this->assertTrue($image->isSize(300, 300));

        // Test string arguments
        $resized = $image->Pad("150", "100");
        $this->assertTrue($resized->isSize(150, 100));

        // Test float arguments (decimal floored away)
        $cropped = $image->Fill(100.1, 200.6);
        $this->assertTrue($cropped->isSize(100, 200));

        // Test string float
        $cropped = $image->Fill("110.1", "210.6");
        $this->assertTrue($cropped->isSize(110, 210));
    }

    /**
     * @dataProvider providerTestImageResizeInvalid
     * @param $width
     * @param $height
     * @param $error
     */
    public function testImageResizeInvalid($width, $height, $error)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $image->Pad($width, $height);
    }

    public function providerTestImageResizeInvalid()
    {
        return [
            ['-1', 100, 'Width must be positive'],
            ['bob', 100, 'Width must be a numeric value'],
            ['0', 100, 'Width is required'],
        ];
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testGenerateImageWithInvalidParameters()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $image->ScaleHeight('String');
        $image->Pad(600, 600, 'XXXXXX');
    }

    public function testCacheFilename()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $imageFirst = $image->Pad(200, 200, 'CCCCCC', 0);
        $imageFilename = $imageFirst->getURL();
            // Encoding of the arguments is duplicated from cacheFilename
        $neededPart = 'Pad' . Convert::base64url_encode(array(200,200,'CCCCCC', 0));
        $this->assertContains($neededPart, $imageFilename, 'Filename for cached image is correctly generated');
    }

    /**
     * Ensure dimensions are cached
     */
    public function testCacheDimensions()
    {
        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        /** @var InterventionBackend $backend */
        $backend = $image->getImageBackend();
        $cache = $backend->getCache();
        $cache->clear();

        /** @var DBFile $imageSecond */
        $imageSecond = $image->Pad(331, 313, '222222', 0);

        // Ensure image dimensions are cached
        $imageKey = $this->getDimensionCacheKey($image->getHash(), '');
        $this->assertTrue($cache->has($imageKey));
        $secondKey = $this->getDimensionCacheKey($imageSecond->getHash(), $imageSecond->getVariant());
        $this->assertTrue($cache->has($secondKey));

        // Ensure that caching to a custom file (no variant) is warmed on output
        $backend->writeToStore(
            Injector::inst()->get(AssetStore::class),
            'bob.jpg',
            sha1('anything'),
            'custom-variant'
        );
        $customKey = $this->getDimensionCacheKey(sha1('anything'), 'custom-variant');
        $this->assertTrue($cache->has($customKey));
    }

    protected function getDimensionCacheKey($hash, $variant)
    {
        return InterventionBackend::CACHE_DIMENSIONS . sha1($hash .'-'.$variant);
    }

    protected function getErrorCacheKey($hash, $variant)
    {
        return InterventionBackend::CACHE_MARK . sha1($hash . '-' . $variant);
    }

    /**
     * Test loading of errors
     */
    public function testCacheErrorLoading()
    {
        // Test loading of inaccessible asset
        /** @var AssetContainer|ObjectProphecy $builder */
        $filename = 'folder/file.jpg';
        $hash = sha1($filename);

        // Mock unavailable asset backend
        $builder = $this->getMockAssetBackend($filename, $hash);
        $builder
            ->getStream()
            ->willReturn(null); // Should safely trigger error in InterventionBackend::getImageResource()
        $container = $builder->reveal();

        // Test backend
        /** @var InterventionBackend $backend */
        $backend = Injector::inst()->createWithArgs(
            Image_Backend::class,
            [$container]
        );
        $cache = $backend->getCache();
        $key = $this->getErrorCacheKey($hash, null);

        // Check error after initial attempt
        $result = $backend->croppedResize(100, 100);
        $this->assertNull($result);
        $this->assertEquals(InterventionBackend::FAILED_MISSING, $cache->get($key .'_reason'));
        $this->assertEquals(0, $cache->get($key . '_ttl'));

        // Subsequent attempts don't advance the rolling TTL
        $result = $backend->croppedResize(100, 100);
        $this->assertNull($result);
        $this->assertEquals(InterventionBackend::FAILED_MISSING, $cache->get($key .'_reason'));
        $this->assertEquals(0, $cache->get($key . '_ttl'));

        // Clear error and attempt another load
        $cache->delete($key.'_reason');
        $result = $backend->croppedResize(200, 200);
        $this->assertNull($result);
        $this->assertEquals(InterventionBackend::FAILED_MISSING, $cache->get($key .'_reason'));
        $this->assertEquals(1, $cache->get($key . '_ttl'));

        // Note that for any subsequent failures we re-use the last TTL for each error
        $cache->delete($key .'_reason');
        $result = $backend->croppedResize(200, 200);
        $this->assertNull($result);
        $this->assertEquals(InterventionBackend::FAILED_MISSING, $cache->get($key .'_reason'));
        $this->assertEquals(1, $cache->get($key . '_ttl'));
    }

    public function testCacheErrorInvalid()
    {
        // Test loading of inaccessible asset
        /** @var AssetContainer|ObjectProphecy $builder */
        $filename = 'folder/not-image.txt';
        $hash = sha1($filename);

        // Get backend which poses as image, but has non-image content
        $builder = $this->getMockAssetBackend($filename, $hash);
        $stream = fopen(__DIR__.'/ImageTest/not-image.txt', 'r');
        $builder
            ->getStream()
            ->willReturn($stream);
        $container = $builder->reveal();

        // Test backend
        /** @var InterventionBackend $backend */
        $backend = Injector::inst()->createWithArgs(
            Image_Backend::class,
            [$container]
        );
        $cache = $backend->getCache();
        $key = $this->getErrorCacheKey($hash, null);

        // Check error after initial attempt
        $result = $backend->croppedResize(100, 100);
        $this->assertNull($result);
        $this->assertEquals(InterventionBackend::FAILED_INVALID, $cache->get($key .'_reason'));
    }

    /**
     * Test that propertes from the source Image are inherited by resampled images
     */
    public function testPropertyInheritance()
    {
        $testString = 'This is a test';
        $origImage = $this->objFromFixture(Image::class, 'imageWithTitle');
        $origImage->TestProperty = $testString;
        $resampled = $origImage->ScaleWidth(10);
        $this->assertEquals($resampled->TestProperty, $testString);
        $resampled2 = $resampled->ScaleWidth(5);
        $this->assertEquals($resampled2->TestProperty, $testString);
    }

    /**
     * @param $filename
     * @param $hash
     * @return ObjectProphecy
     */
    protected function getMockAssetBackend($filename, $hash)
    {
        $builder = $this->prophesize(AssetContainer::class);
        $builder->exists()->willReturn(true);
        $builder->getFilename()->willReturn($filename);
        $builder->getHash()->willReturn($hash);
        $builder->getVariant()->willReturn(null);
        $builder->getIsImage()->willReturn(true);
        return $builder;
    }
}

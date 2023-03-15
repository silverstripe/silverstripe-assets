<?php

namespace SilverStripe\Assets\Tests;

use Prophecy\Prophecy\ObjectProphecy;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Assets\Tests\ImageManipulationTest\LazyLoadAccessorExtension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\View\SSViewer;

/**
 * ImageTest is abstract and should be overridden with manipulator-specific subtests
 * @skipUpgrade
 */
class ImageManipulationTest extends SapphireTest
{
    protected static $fixture_file = 'ImageTest.yml';

    protected function setUp(): void
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
            $image->publishSingle();
        }

        // Set default config
        InterventionBackend::config()->set('error_cache_ttl', [
            InterventionBackend::FAILED_INVALID => 0,
            InterventionBackend::FAILED_MISSING => '5,10',
            InterventionBackend::FAILED_UNKNOWN => 300,
        ]);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testAttribute()
    {
        /** @var Image $origin */
        $origin = $this->objFromFixture(Image::class, 'imageWithTitle');

        $this->assertEmpty(
            $origin->getAttribute('foo'),
            'Undefined attributes are falsy'
        );

        /** @var DBFile $image */
        $image = $origin->setAttribute('foo', 'bar');
        $this->assertEquals(
            'bar',
            $image->getAttribute('foo'),
            'Attributes has been set'
        );
        $this->assertNotEquals(
            $origin,
            $image,
            'setAttribute returns a copy of the original'
        );

        $image = $image->ScaleHeight(123);
        $this->assertEquals(
            'bar',
            $image->getAttribute('foo'),
            'Image Manipulation preserve attributes'
        );

        $image = $image->setAttribute('bar', 'foo');
        $this->assertEquals(
            'foo',
            $image->getAttribute('bar'),
            'New attribute is added'
        );
        $this->assertEquals(
            'bar',
            $image->getAttribute('foo'),
            'Old attributes are preserved'
        );

        $newImage = $image->setAttribute('bar', 'bar');
        $this->assertEquals(
            'foo',
            $image->getAttribute('bar'),
            'Old image attribute don\'t get overriden by set attribute'
        );
    }

    public function testInitAttributes()
    {
        /** @var Image $origin */
        $origin = $this->objFromFixture(Image::class, 'imageWithTitle');

        $origin->initAttributes(['foo' => 'bar']);
        $this->assertEquals(
            'bar',
            $origin->getAttribute('foo'),
            'Attributes hawve been set'
        );

        /** @var DBFile $dbfile */
        $dbfile = $origin->setAttribute('hello', 'bonjour');
        $dbfile->initAttributes(['bar' => 'foo']);
        $this->assertEquals(
            'foo',
            $dbfile->getAttribute('bar'),
            'Attributes have been set'
        );
        $this->assertEmpty(
            $dbfile->getAttribute('hello'),
            'Pre-existing attributes have been unset'
        );
    }

    public function testGetAttributesForImage()
    {
        /** @var Image $origin */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');

        $this->assertEquals(
            [
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'alt' => $image->getTitle(),
                'src' => $image->getURL(),
                'loading' => 'lazy'
            ],
            $image->getAttributes()
        );

        $this->assertEquals(
            [
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'alt' => $image->getTitle(),
                'src' => $image->getURL(),
                'loading' => 'lazy',
                'class' => 'foo'
            ],
            $image->setAttribute('class', 'foo')->getAttributes(),
            'all attributes are honored'
        );

        $croped = $image->CropHeight(10)->CropWidth(15);
        $this->assertEquals(
            [
                'width' => 15,
                'height' => 10,
                'alt' => $image->getTitle(),
                'src' => $croped->getURL(),
                'loading' => 'lazy'
            ],
            $croped->getAttributes(),
            'Dimension adjust to image variant'
        );

        $eager = $image->LazyLoad(false);
        $this->assertEquals(
            [
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'alt' => $image->getTitle(),
                'src' => $image->getURL(),
            ],
            $eager->getAttributes(),
            'Eager image do not have a loading attribute'
        );

        $this->assertEquals(
            [
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'alt' => $image->getTitle(),
                'src' => $image->getURL(),
                'loading' => 'lazy'
            ],
            $eager->LazyLoad(true)->getAttributes(),
            'Lazy loading can be re-enabled'
        );
    }

    public function testGetAttributesWhenLazyLoadIsDisabled()
    {
        Config::withConfig(function () {
            Config::modify()->set(Image::class, 'lazy_loading_enabled', false);
            /** @var Image $origin */
            $image = $this->objFromFixture(Image::class, 'imageWithTitle');

            $this->assertEquals(
                [
                    'width' => $image->getWidth(),
                    'height' => $image->getHeight(),
                    'alt' => $image->getTitle(),
                    'src' => $image->getURL(),
                ],
                $image->getAttributes(),
                'No loading attribute when lazy loading is disabled globally'
            );

            $this->assertEquals(
                [
                    'width' => $image->getWidth(),
                    'height' => $image->getHeight(),
                    'alt' => $image->getTitle(),
                    'src' => $image->getURL(),
                ],
                $image->LazyLoad(true)->getAttributes(),
                'No loading attribute when lazy loading is disabled globally even if requested'
            );
        });
    }

    public function testGetAttributesForPlainFile()
    {
        $file = File::create();
        $file->setFromString('boom', 'boom.txt');

        $this->assertEquals([], $file->getAttributes(), 'Plain files do not have attribute out of the box');
        $this->assertEquals(
            ['class' => 'foo'],
            $file->setAttribute('class', 'foo')->getAttributes(),
            'Plain files honour setAttribute calls'
        );
    }

    public function testIsLazyLoaded()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');

        $this->assertTrue($image->IsLazyLoaded(), 'Image default to lazy load');
        $this->assertFalse(
            $image->LazyLoad(false)->IsLazyLoaded(),
            'Disabling LazyLoad turns off lazy load'
        );

        Config::withConfig(function () use ($image) {
            Config::modify()->set(Image::class, 'lazy_loading_enabled', false);
            $this->assertFalse(
                $image->IsLazyLoaded(),
                'Disabling LazyLoad globally turns off lazy load'
            );
        });

        $file = File::create();
        $file->setFromString('boom', 'boom.txt');
        $this->assertFalse(
            $file->IsLazyLoaded(),
            'Plain files are never lazy loaded'
        );
    }

    public function testAttributesHTML()
    {
        /** @var Image $origin */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $this->assertEquals(
            'width="300" height="300" alt="This is a image Title" src="/assets/ImageTest/folder/test-image.png" loading="lazy"',
            $image->getAttributesHTML(),
            'Attributes are converted to HTML'
        );
    }

    public function lazyLoadProvider()
    {
        return [
            'false (bool)' => [false, false],
            '0 (int)' => [0, false],
            '0 (string)' => ['0', false],
            'false (string)' => ['false', false],
            'false (uppercase)' => ['FALSE', false],

            'true (bool)' => [true, true],
            '1 (int)' => [1, true],
            '1 (string)' => ['1', true],
            'true (string)' => ['true', true],
            'true (mixed case)' => ['tRuE', true],
        ];
    }

    /**
     * @param $val
     * @param $expected
     * @dataProvider lazyLoadProvider
     */
    public function testLazyLoad($val, bool $expected)
    {
        /** @var Image $origin */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');

        $newImg = $image->LazyLoad($val);

        $this->assertNotEquals($image, $newImg, 'LazyLoad is immutable');
        $this->assertEquals($expected, $newImg->IsLazyLoaded());

        // Disable Lazy load to make sure we don't blindly accept a truty value
        $this->assertEquals(
            $expected,
            $image->LazyLoad(false)->LazyLoad($val)->IsLazyLoaded()
        );
    }

    public function lazyLoadBadProvider()
    {
        return [
            'null' => [null],
            'negative value' => [-1],
            'empty string' => [''],
            'number other than 1' => [2],
            'invalid string' => ['nonsense'],
            'eager' => ['eager'],
            'lazy' => ['lazy']
        ];
    }

    /**
     * @param $val
     * @dataProvider lazyLoadBadProvider
     */
    public function testBadLazyLoad($val)
    {
        /** @var Image $origin */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');

        $newImg = $image->LazyLoad($val);

        $this->assertEquals(
            $image,
            $newImg,
            'An invalid Lazyload value return same object'
        );
        $this->assertEquals(
            true,
            $newImg->IsLazyLoaded(),
            'Invalid value does not change Lazy Load Value'
        );

        // Disable Lazy load to make sure we don't blindly accept a truty value
        $this->assertEquals(
            false,
            $image->LazyLoad(false)->LazyLoad($val)->IsLazyLoaded(),
            'Invalid value does not change Lazy Load Value'
        );
    }

    public function testLazyLoadIsAccessibleInExtensions()
    {
        Image::add_extension(LazyLoadAccessorExtension::class);

        /** @var Image $origin */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');

        $this->assertTrue(
            $image->LazyLoad(true)->getLazyLoadValueViaExtension(),
            'Incorrect LazyLoad value reported by extension'
        );
        $this->assertFalse(
            $image->LazyLoad(false)->getLazyLoadValueViaExtension(),
            'Incorrect LazyLoad value reported by extension'
        );
        $this->assertTrue(
            $image->LazyLoad(false)->LazyLoad(true)->getLazyLoadValueViaExtension(),
            'Incorrect LazyLoad value reported by extension'
        );

        Image::remove_extension(LazyLoadAccessorExtension::class);
    }

    public function renderProvider()
    {
        $alt = 'This is a image Title';
        $src = '/assets/ImageTest/folder/test-image.png';
        $srcCroped = '/assets/ImageTest/folder/test-image__CropWidthWyIxMDAiXQ.png';

        return [
            'Simple output' => [
                '$Me',
                '<img width="300" height="300" alt="'.$alt.'" src="'.$src.'" loading="lazy" />'
            ],
            'LazyLoad off' => [
                '$Me.LazyLoad(false)',
                '<img width="300" height="300" alt="'.$alt.'" src="'.$src.'" />'
            ],
            'LazyLoad off then on' => [
                '$Me.LazyLoad(false).LazyLoad(true)',
                '<img width="300" height="300" alt="'.$alt.'" src="'.$src.'" loading="lazy" />'
            ],

            'ImageManipulation' => [
                '$Me.CropWidth(100)',
                '<img width="100" height="300" alt="'.$alt.'" src="'.$srcCroped.'" loading="lazy" />'
            ],
            'ImageManipulation then LazyLoad off' => [
                '$Me.CropWidth(100).LazyLoad(false)',
                '<img width="100" height="300" alt="'.$alt.'" src="'.$srcCroped.'" />'
            ],
            'LazyLoad off then ImageManipulation' => [
                '$Me.LazyLoad(false).CropWidth(100)',
                '<img width="100" height="300" alt="'.$alt.'" src="'.$srcCroped.'" />'
            ],
            'LazyLoad off then ImageManipulation then LazyLoad ON' => [
                '$Me.LazyLoad(false).CropWidth(100).LazyLoad(true)',
                '<img width="100" height="300" alt="'.$alt.'" src="'.$srcCroped.'" loading="lazy" />'
            ],

            'Simple + eager loaded' => [
                '$Me $Me.LazyLoad(false)',
                '<img width="300" height="300" alt="'.$alt.'" src="'.$src.'" loading="lazy" />' .
                 "\n " . '<img width="300" height="300" alt="'.$alt.'" src="'.$src.'" />'
            ],
            'Eager loaded + Lazy Loaded' => [
                '$Me.LazyLoad(false) $Me.LazyLoad(true)',
                '<img width="300" height="300" alt="'.$alt.'" src="'.$src.'" />' . "\n " .
                '<img width="300" height="300" alt="'.$alt.'" src="'.$src.'" loading="lazy" />'
            ],

        ];
    }

    /**
     * @dataProvider renderProvider
     */
    public function testRender(string $template, string $expected)
    {
        /** @var Image $origin */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');

        $this->assertEquals(
            $expected,
            trim($image->renderWith(SSViewer::fromString($template)) ?? '')
        );
    }

    public function testThumbnailURL()
    {
        $img = $this->objFromFixture(Image::class, 'imageWithTitle');

        // File needs to be in draft and users need to be anonymous to test the access
        $this->logOut();
        $img->doUnpublish();

        $fileUrl = 'folder/444065542b/test-image__FillWzEwLDEwXQ.png';

        $this->assertEquals(
            '/assets/' . $fileUrl,
            $img->ThumbnailURL(10, 10),
            'Thumbnail URL is correct'
        );

        /** @var AssetStore assetStore */
        $assetStore = Injector::inst()->get(AssetStore::class);
        $this->assertFalse(
            $assetStore->isGranted($fileUrl),
            'Current user should not automatically be granted access to view thumbnail'
        );
    }
}

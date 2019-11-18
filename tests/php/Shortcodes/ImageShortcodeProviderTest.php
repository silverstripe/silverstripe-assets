<?php

namespace SilverStripe\Assets\Tests\Shortcodes;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Folder;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\ShortcodeParser;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Shortcodes\ImageShortcodeProvider;

/**
 * @skipUpgrade
 */
class ImageShortcodeProviderTest extends SapphireTest
{
    protected static $fixture_file = '../ImageTest.yml';

    public function setUp()
    {
        parent::setUp();

        // Set backend root to /ImageTest
        TestAssetStore::activate('ImageTest');

        // Copy test images for each of the fixture references
        $images = Image::get();
        /** @var Image $image */
        foreach ($images as $image) {
            $sourcePath = __DIR__ . '/../ImageTest/' . $image->Name;
            $image->setFromLocalFile($sourcePath, $image->Filename);
        }
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testShortcodeHandlerFallsBackToFileProperties()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $parser = new ShortcodeParser();
        $parser->register('image', [ImageShortcodeProvider::class, 'handle_shortcode']);

        $this->assertEquals(
            sprintf(
                '<img src="%s" alt="This is a image Title">',
                $image->Link()
            ),
            $parser->parse(sprintf('[image id=%d]', $image->ID))
        );
    }

    public function testShortcodeHandlerUsesShortcodeProperties()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $parser = new ShortcodeParser();
        $parser->register('image', [ImageShortcodeProvider::class, 'handle_shortcode']);

        $this->assertEquals(
            sprintf(
                '<img src="%s" alt="Alt content" title="Title content">',
                $image->Link()
            ),
            $parser->parse(sprintf(
                '[image id="%d" alt="Alt content" title="Title content"]',
                $image->ID
            ))
        );
    }

    public function testShortcodeHandlerAddsDefaultAttributes()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $parser = new ShortcodeParser();
        $parser->register('image', [ImageShortcodeProvider::class, 'handle_shortcode']);

        $this->assertEquals(
            sprintf(
                '<img src="%s" alt="test image">',
                $image->Link()
            ),
            $parser->parse(sprintf(
                '[image id="%d"]',
                $image->ID
            ))
        );
    }

    public function testShortcodeHandlerFailsGracefully()
    {
        $parser = new ShortcodeParser();
        $parser->register('image', [ImageShortcodeProvider::class, 'handle_shortcode']);

        $nonExistentImageID = $this->getNonExistentImageID();
        $expected = '<img alt="Image not found">';
        $shortcodes = [
            '[image id="' . $nonExistentImageID . '"]',
            '[image id="' . $nonExistentImageID . '" alt="my-alt-attr"]',
        ];
        foreach ($shortcodes as $shortcode) {
            $actual = $parser->parse($shortcode);
            $this->assertEquals($expected, $actual);
        }
    }

    public function testMissingImageDoesNotCache()
    {

        $parser = new ShortcodeParser();
        $parser->register('image', [ImageShortcodeProvider::class, 'handle_shortcode']);

        $nonExistentImageID = $this->getNonExistentImageID();
        $shortcode = '[image id="' . $nonExistentImageID . '"]';

        // make sure cache is not populated from a previous test
        $cache = ImageShortcodeProvider::getCache();
        $cache->clear();

        $args = ['id' => (string) $nonExistentImageID];
        $cacheKey = ImageShortcodeProvider::getCacheKey($args);

        // assert that cache is empty before parsing shortcode
        $this->assertTrue(is_null($cache->get($cacheKey)));

        $parser->parse($shortcode);

        // assert that cache is still empty after parsing shortcode
        $this->assertTrue(is_null($cache->get($cacheKey)));
    }

    private function getNonExistentImageID()
    {
        $nonExistentImageID = 9999;
        while (Image::get()->byID($nonExistentImageID)) {
            $nonExistentImageID++;
        }
        return $nonExistentImageID;
    }
}

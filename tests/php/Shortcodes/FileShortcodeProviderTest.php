<?php

namespace SilverStripe\Assets\Tests\Shortcodes;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\ShortcodeParser;

/**
 * @skipUpgrade
 */
class FileShortcodeProviderTest extends SapphireTest
{
    protected static $fixture_file = '../FileTest.yml';

    public function setUp()
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
        Versioned::set_stage(Versioned::DRAFT);
        // Set backend root to /FileTest
        TestAssetStore::activate('FileTest');

        // Create a test files for each of the fixture references
        $fileIDs = array_merge(
            $this->allFixtureIDs(File::class),
            $this->allFixtureIDs(Image::class)
        );
        foreach ($fileIDs as $fileID) {
            /** @var File $file */
            $file = DataObject::get_by_id(File::class, $fileID);
            $file->setFromString(str_repeat('x', 1000000), $file->getFilename());
        }

        // Conditional fixture creation in case the 'cms' and 'errorpage' modules are installed
        if (class_exists(ErrorPage::class)) {
            Config::inst()->update(SiteTree::class, 'create_default_pages', true);
            ErrorPage::singleton()->requireDefaultRecords();
        }
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testLinkShortcodeHandler()
    {
        /** @var File $testFile */
        $testFile = $this->objFromFixture(File::class, 'asdf');

        $parser = new ShortcodeParser();
        $parser->register('file_link', [FileShortcodeProvider::class, 'handle_shortcode']);

        $fileShortcode = sprintf('[file_link,id=%d]', $testFile->ID);
        $fileEnclosed  = sprintf('[file_link,id=%d]Example Content[/file_link]', $testFile->ID);

        $fileShortcodeExpected = $testFile->Link();
        $fileEnclosedExpected = sprintf(
            '<a href="%s" class="file" data-type="txt" data-size="977 KB">Example Content</a>',
            $testFile->Link()
        );

        $this->assertEquals($fileShortcodeExpected, $parser->parse($fileShortcode), 'Test that simple linking works.');
        $this->assertEquals($fileEnclosedExpected, $parser->parse($fileEnclosed), 'Test enclosed content is linked.');

        $testFile->delete();

        $fileShortcode = '[file_link,id="-1"]';
        $fileEnclosed  = '[file_link,id="-1"]Example Content[/file_link]';

        $this->assertEquals('', $parser->parse('[file_link]'), 'Test that invalid ID attributes are not parsed.');
        $this->assertEquals('', $parser->parse('[file_link,id="text"]'));
        $this->assertEquals('', $parser->parse('[file_link]Example Content[/file_link]'));

        if (class_exists(ErrorPage::class)) {
            /** @var ErrorPage $errorPage */
            $errorPage = ErrorPage::get()->filter('ErrorCode', 404)->first();
            $this->assertEquals(
                $errorPage->Link(),
                $parser->parse($fileShortcode),
                'Test link to 404 page if no suitable matches.'
            );
            $this->assertEquals(
                sprintf('<a href="%s">Example Content</a>', $errorPage->Link()),
                $parser->parse($fileEnclosed)
            );
        } else {
            $this->assertEquals(
                '',
                $parser->parse($fileShortcode),
                'Short code is removed if file record is not present.'
            );
            $this->assertEquals('', $parser->parse($fileEnclosed));
        }
    }

    public function testMissingFileDoesNotCache()
    {
        $parser = new ShortcodeParser();
        $parser->register('file_link', [FileShortcodeProvider::class, 'handle_shortcode']);

        $nonExistentFileID = $this->getNonExistentFileID();
        $shortcode = '[file_link id="' . $nonExistentFileID . '"]';

        // make sure cache is not populated from a previous test
        $cache = FileShortcodeProvider::getCache();
        $cache->clear();

        $args = ['id' => (string) $nonExistentFileID];
        $cacheKey = FileShortcodeProvider::getCacheKey($args, "");

        // assert that cache is empty before parsing shortcode
        $this->assertTrue(is_null($cache->get($cacheKey)));

        $parser->parse($shortcode);

        // assert that cache is still empty after parsing shortcode
        $this->assertTrue(is_null($cache->get($cacheKey)));
    }

    private function getNonExistentFileID()
    {
        $nonExistentFileID = 9999;
        while (Image::get()->byID($nonExistentFileID)) {
            $nonExistentFileID++;
        }
        return $nonExistentFileID;
    }
}

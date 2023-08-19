<?php

namespace SilverStripe\Assets\Tests\Shortcodes;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
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

    protected function setUp(): void
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
            Config::inst()->set(SiteTree::class, 'create_default_pages', true);
            ErrorPage::singleton()->requireDefaultRecords();
        }
    }

    protected function tearDown(): void
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

        $nonExistentFileID = File::get()->max('ID') + 1;
        $shortcode = '[file_link id="' . $nonExistentFileID . '"]';

        // make sure cache is not populated from a previous test
        $cache = FileShortcodeProvider::getCache();
        $cache->clear();

        $args = ['id' => (string) $nonExistentFileID];
        $cacheKey = FileShortcodeProvider::getCacheKey($args, "");

        // assert that cache is empty before parsing shortcode
        $this->assertNull($cache->get($cacheKey));

        $parser->parse($shortcode);

        // assert that cache is still empty after parsing shortcode
        $this->assertNull($cache->get($cacheKey));
    }

    public function testOnlyGrantsAccessWhenConfiguredTo()
    {
        /** @var AssetStore $assetStore */
        $assetStore = Injector::inst()->get(AssetStore::class);

        /** @var File $testFile */
        $testFile = $this->objFromFixture(File::class, 'asdf');

        $parser = new ShortcodeParser();
        $parser->register('file_link', [FileShortcodeProvider::class, 'handle_shortcode']);

        $parser->parse(sprintf('[file_link,id=%d]', $testFile->ID));
        $this->assertFalse($assetStore->isGranted($testFile));

        FileShortcodeProvider::config()->set('allow_session_grant', true);

        $parser->parse(sprintf('[file_link,id=%d]', $testFile->ID));
        $this->assertFalse($assetStore->isGranted($testFile));
    }

    public function testMarkupHasStringValue()
    {
        $testFile = $this->objFromFixture(File::class, 'asdf');
        $testFileID = $testFile->ID;
        $tuple = $testFile->File->getValue();

        $assetStore = Injector::inst()->get(AssetStore::class);

        $parser = new ShortcodeParser();
        $parser->register('file_link', [FileShortcodeProvider::class, 'handle_shortcode']);

        FileShortcodeProvider::config()->set('allow_session_grant', true);

        $fileShortcode = sprintf('[file_link,id=%d]', $testFileID);
        $this->assertEquals(
            $testFile->Link(),
            $parser->parse(sprintf('[file_link,id=%d]', $testFileID)),
            'Test that shortcode with existing file ID is parsed.'
        );

        $testFile->deleteFile();
        $this->assertFalse(
            $assetStore->exists($tuple['Filename'], $tuple['Hash']),
            'Test that file was removed from Asset store.'
        );

        $this->assertEquals(
            '',
            $parser->parse(sprintf('[file_link,id=%d]', $testFileID)),
            'Test that shortcode with invalid file ID is not parsed.'
        );
    }
}

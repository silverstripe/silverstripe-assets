<?php

namespace SilverStripe\Assets\Tests\Shortcodes;

use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\View\Parsers\ShortcodeParser;
use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use SilverStripe\Assets\File;

/**
 * Class FileShortcodeProviderTest
 */
class FileShortcodeProviderTest extends SapphireTest
{

    protected static $fixture_file = '../FileTest.yml';

    public function testLinkShortcodeHandler() {
        $testFile = $this->objFromFixture(File::class, 'asdf');

        $parser = new ShortcodeParser();
        $parser->register('file_link', [FileShortcodeProvider::class, 'handle_shortcode']);

        $fileShortcode = sprintf('[file_link,id=%d]', $testFile->ID);
        $fileEnclosed  = sprintf('[file_link,id=%d]Example Content[/file_link]', $testFile->ID);

        $fileShortcodeExpected = $testFile->Link();
        $fileEnclosedExpected  = sprintf(
            '<a href="%s" class="file" data-type="txt" data-size="">Example Content</a>', $testFile->Link());

        $this->assertEquals($fileShortcodeExpected, $parser->parse($fileShortcode), 'Test that simple linking works.');
        $this->assertEquals($fileEnclosedExpected, $parser->parse($fileEnclosed), 'Test enclosed content is linked.');

        $testFile->delete();

        $fileShortcode = '[file_link,id="-1"]';
        $fileEnclosed  = '[file_link,id="-1"]Example Content[/file_link]';

        $this->assertEquals('', $parser->parse('[file_link]'), 'Test that invalid ID attributes are not parsed.');
        $this->assertEquals('', $parser->parse('[file_link,id="text"]'));
        $this->assertEquals('', $parser->parse('[file_link]Example Content[/file_link]'));

        if(class_exists(ErrorPage::class)) {
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
            $this->assertEquals('', $parser->parse($fileShortcode),
                'Short code is removed if file record is not present.');
            $this->assertEquals('', $parser->parse($fileEnclosed));
        }
    }
}
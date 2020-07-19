<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\SapphireTest;

class AlternativeFileExtensionTest extends SapphireTest
{


    public function rewriteDataProvider()
    {
        $jpgToPng = 'extRewrite' . Convert::base64url_encode(['jpg', 'png']);

        return [
            'no variant' => ['', 'hello.txt', 'hello.txt'],
            'invalid extension' => ['xyz', 'hello.abc+', 'hello.abc+'],
            'no extension' => ['', 'hello.', 'hello.'],
            'no filename' => ['', '.htaccess', '.htaccess'],
            'no rewrite' => ['xyz', 'hello.jpg', 'hello.jpg'],
            'no rewrite multi variant' => ['xyz_abc', 'hello.jpg', 'hello.jpg'],
            'rewitten extension' => [$jpgToPng, 'hello.jpg', 'hello.png'],
            'rewitten extension with other variants' => ["{$jpgToPng}_xyz", 'hello.jpg', 'hello.png'],
        ];
    }

    /**
     * @dataProvider rewriteDataProvider
     */
    public function testRewriteVariantExtension($variant, $inFilename, $outFilename)
    {
        $helper = new HashFileIDHelper();
        $actualFilename = $helper->rewriteVariantExtension($inFilename, $variant);

        $this->assertEquals($outFilename, $actualFilename);
    }

    /**
     * @dataProvider rewriteDataProvider
     */
    public function testRestoreOriginalExtension($variant, $outFilename, $inFilename)
    {
        $helper = new HashFileIDHelper();
        $actualFilename = $helper->restoreOriginalExtension($inFilename, $variant);

        $this->assertEquals($outFilename, $actualFilename);
    }
}

<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use InvalidArgumentException;
use ReflectionClassConstant;
use ReflectionMethod;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Core\Convert;

class HashFileIDHelperTest extends FileIDHelperTester
{

    protected function getHelper()
    {
        return new HashFileIDHelper();
    }

    public function fileIDComponents()
    {
        return [
            // Common use case
            ['abcdef7890/sam.jpg', ['sam.jpg', 'abcdef7890']],
            ['subfolder/abcdef7890/sam.jpg', ['subfolder/sam.jpg', 'abcdef7890']],
            ['subfolder/abcdef7890/sam__resizeXYZ.jpg', ['subfolder/sam.jpg', 'abcdef7890', 'resizeXYZ']],
            ['abcdef7890/sam__resizeXYZ.jpg', ['sam.jpg', 'abcdef7890', 'resizeXYZ']],
            // Edge casey scenario
            ['subfolder/under_score/abcdef7890/sam__resizeXYZ.jpg', [
                'subfolder/under_score/sam.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/under_score/abcdef7890/sam_single-underscore__resizeXYZ.jpg', [
                'subfolder/under_score/sam_single-underscore.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/under_score/abcdef7890/sam_double_dots.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', 'abcdef7890'
            ]],
            [
                'subfolder/under_score/abcdef7890/sam_double_dots__resizeXYZ.tar.gz', [
                    'subfolder/under_score/sam_double_dots.tar.gz', 'abcdef7890', 'resizeXYZ'
                ]],
            [
                'subfolder/under_score/abcdef7890/sam_double_dots__stack_variant.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', 'abcdef7890', 'stack_variant'
                ]],
        ];
    }

    public function dirtyFileIDComponents()
    {
        return [
            // Cases that need clean up
            ['abcdef7890/sam_double-under-score.jpg', [
                'sam__double-under-score.jpg', 'abcdef7890'
            ]],
            ['abcdef7890/sam_double-under-score__resizeXYZ.jpg', [
                'sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/abcdef7890/sam_double-under-score__resizeXYZ.jpg', [
                'subfolder/sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
        ];
    }

    public function dirtyFileIDFromDirtyTuple()
    {
        return [
            // Cases that need clean up
            ['abcdef7890/sam__double-under-score.jpg', [
                'sam__double-under-score.jpg', 'abcdef7890'
            ]],
            ['abcdef7890/sam__double-under-score__resizeXYZ.jpg', [
                'sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/abcdef7890/sam__double-under-score__resizeXYZ.jpg', [
                'subfolder/sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
        ];
    }

    function dirtyFilenames()
    {
        return [
            ['sam.jpg', 'sam.jpg'],
            ['subfolder/sam.jpg', 'subfolder/sam.jpg'],
            ['sub_folder/sam.jpg', 'sub_folder/sam.jpg'],
            ['sub_folder/double_underscore.jpg', 'sub_folder/double__underscore.jpg'],
            ['sub_folder/single_underscore.jpg', 'sub_folder/single_underscore.jpg'],
            ['sub_folder/triple_underscore.jpg', 'sub_folder/triple___underscore.jpg'],
            ['Folder/With/Backslash/file.jpg', 'Folder\With\Backslash\file.jpg'],
        ];
    }

    public function brokenFileID()
    {
        return [
            ['sam.jpg'],
            ['sam__resizeXYZ.jpg'],
            ['folder//sam.jpg'],
            ['folder/not10characters/sam.jpg'],
            ['folder/10invachar/sam.jpg'],
            ['folder/abcdef1234567890/more-than-10-hexadecimal-char.jpg'],
            ['folder/abcdef/less-than-10-hexadecimal-char.jpg'],
        ];
    }

    public function variantOf()
    {
        return [
            [
                'abcdef7890/sam__ResizeXYZ.jpg',
                new ParsedFileID('sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'abcdef7890/sam.jpg',
                new ParsedFileID('sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'folder/abcdef7890/sam__ResizeXYZ.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'folder/abcdef7890/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'folder/abcdef7890/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef78901', 'truncate-10-char-hash'),
                true
            ],
            [
                'folder/abcdef7890/sam__ResizeXYZ.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890', 'ResizeXXX'),
                true
            ],
            [
                'folder/abcdef7890/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890', 'ResizeXXX'),
                true
            ],
            [
                'abcdef7890/sam__ResizeXYZ.jpg',
                new ParsedFileID('wrong-folder/sam.jpg', 'abcdef7890'),
                false
            ],
            [
                'abcdef7890/sam__ResizeXYZ.jpg',
                new ParsedFileID('sam.jpg', 'badhash'),
                false
            ],
            [
                'folder/abcdef7890/sam__ResizeXYZ.jpg',
                new ParsedFileID('folder-wrong-file-name.jpg', 'abcdef7890'),
                false
            ],
        ];
    }

    public function variantIn()
    {
        return [
            [new ParsedFileID('sam.jpg', 'abcdef7890'), 'abcdef7890'],
            [new ParsedFileID('folder/sam.jpg', 'abcdef7890'), 'folder/abcdef7890'],
            [new ParsedFileID('sam.jpg', 'abcdef7890'), 'abcdef7890'],
            [new ParsedFileID('folder/sam.jpg', 'abcdef7890'), 'folder/abcdef7890'],
            [new ParsedFileID('folder/truncate-hash.jpg', 'abcdef78901'), 'folder/abcdef7890'],
            [new ParsedFileID('folder/truncate-hash.jpg', 'abcdef7890', 'ResizeXXX'), 'folder/abcdef7890'],
        ];
    }

    public function testHashlessBuildFileID()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->getHelper()->buildFileID('Filename.txt', '');
    }

    public function provideRewriteExtension()
    {
        $jpgToPng = 'ExtRewrite' . Convert::base64url_encode(['jpg', 'png']);

        return [
            'no variant' => ['', 'hel_lo.txt', 'hel_lo.txt'],
            'invalid extension' => ['xyz', 'h_ello.abc+', 'h_ello.abc+'],
            'no extension' => ['', 'hell_o.', 'hell_o.'],
            'no filename' => ['', '.hta_ccess', '.hta_ccess'],
            'no rewrite' => ['xyz', 'hell_o.jpg', 'hell_o.jpg'],
            'no rewrite multi variant' => ['xyz_abc', 'he_llo.jpg', 'he_llo.jpg'],
            'rewitten extension' => [$jpgToPng, 'hel_lo.jpg', 'hel_lo.png'],
            'rewitten extension with other variants' => ["{$jpgToPng}_xyz", 'hel_lo.jpg', 'hel_lo.png'],
        ];
    }

    /**
     * @dataProvider provideRewriteExtension
     */
    public function testRewriteVariantExtension($variant, $inFilename, $outFilename)
    {
        $helper = new HashFileIDHelper();
        $reflectionMethod = new ReflectionMethod($helper, 'swapExtension');
        $reflectionMethod->setAccessible(true);
        $reflectionConst = new ReflectionClassConstant($helper, 'EXTENSION_VARIANT');
        $actualFilename = $reflectionMethod->invoke($helper, $inFilename, $variant, $reflectionConst->getValue());

        $this->assertEquals($outFilename, $actualFilename);
    }

    /**
     * @dataProvider provideRewriteExtension
     */
    public function testRestoreOriginalExtension($variant, $outFilename, $inFilename)
    {
        $helper = new HashFileIDHelper();
        $reflectionMethod = new ReflectionMethod($helper, 'swapExtension');
        $reflectionMethod->setAccessible(true);
        $reflectionConst = new ReflectionClassConstant($helper, 'EXTENSION_ORIGINAL');
        $actualFilename = $reflectionMethod->invoke($helper, $inFilename, $variant, $reflectionConst->getValue());

        $this->assertEquals($outFilename, $actualFilename);
    }
}

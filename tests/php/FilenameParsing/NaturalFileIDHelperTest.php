<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;

class NaturalFileIDHelperTest extends FileIDHelperTester
{

    protected static function getHelper()
    {
        return new NaturalFileIDHelper();
    }

    public static function fileIDComponents()
    {
        return [
            // Common use case
            ['sam.jpg', ['sam.jpg', '']],
            ['subfolder/sam.jpg', ['subfolder/sam.jpg', '']],
            ['subfolder/sam__resizeXYZ.jpg', ['subfolder/sam.jpg', '', 'resizeXYZ']],
            ['subfolder/abcdef7890/sam__resizeXYZ.jpg', ['subfolder/abcdef7890/sam.jpg', '', 'resizeXYZ']],
            ['sam__resizeXYZ.jpg', ['sam.jpg', '', 'resizeXYZ']],
            // Edge casey scenario
            ['subfolder/under_score/sam__resizeXYZ.jpg', [
                'subfolder/under_score/sam.jpg', '', 'resizeXYZ'
            ]],
            ['subfolder/under_score/sam_single-underscore__resizeXYZ.jpg', [
                'subfolder/under_score/sam_single-underscore.jpg', '', 'resizeXYZ'
            ]],
            ['subfolder/under_score/sam_single-underscore__resizeXYZ_scaleheightABC.jpg', [
                'subfolder/under_score/sam_single-underscore.jpg', '', 'resizeXYZ_scaleheightABC'
            ]],
            ['subfolder/under_score/sam_double_dots.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', ''
            ]],
            ['subfolder/under_score/sam_double_dots__resizeXYZ.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', '', 'resizeXYZ'
            ]],
            ['subfolder/under_score/sam_double_dots__stack_variant.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', '', 'stack_variant'
            ]],
        ];
    }

    public static function dirtyFileIDComponents()
    {
        return [
            ['sam.jpg', [
                'sam.jpg', 'abcdef7890'
            ]],
            ['subfolder/sam.jpg', [
                'subfolder/sam.jpg', 'abcdef7890'
            ]],
            ['sam_double-under-score.jpg', [
                'sam__double-under-score.jpg', ''
            ]],
            ['sam_double-under-score__resizeXYZ.jpg', [
                'sam__double-under-score.jpg', '', 'resizeXYZ'
            ]],
            ['subfolder/sam_double-under-score__resizeXYZ.jpg', [
                'subfolder/sam__double-under-score.jpg', '', 'resizeXYZ'
            ]],
            ['subfolder/sam_double-under-score__resizeXYZ_scaleheightABC.jpg', [
                'subfolder/sam__double-under-score.jpg', '', 'resizeXYZ_scaleheightABC'
            ]],
            ['sam_double-under-score__resizeXYZ.jpg', [
                'sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/sam_double-under-score__resizeXYZ.jpg', [
                'subfolder/sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
        ];
    }

    public static function dirtyFileIDFromDirtyTuple()
    {
        return [
            ['sam__double-under-score.jpg', [
                'sam__double-under-score.jpg', ''
            ]],
            ['sam__double-under-score__resizeXYZ.jpg', [
                'sam__double-under-score.jpg', '', 'resizeXYZ'
            ]],
            ['subfolder/sam__double-under-score__resizeXYZ.jpg', [
                'subfolder/sam__double-under-score.jpg', '', 'resizeXYZ'
            ]],
            ['sam__double-under-score__resizeXYZ.jpg', [
                'sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/sam__double-under-score__resizeXYZ.jpg', [
                'subfolder/sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/sam__double-under-score__resizeXYZ_scaleheightABC.jpg', [
                'subfolder/sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ_scaleheightABC'
            ]],
        ];
    }

    public static function dirtyFilenames()
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

    public static function brokenFileID()
    {
        return [
            ['/sam.jpg'],
            ['/no-slash-start/sam__resizeXYZ.jpg'],
            ['folder//sam.jpg']
        ];
    }

    public static function variantOf()
    {
        return [
            [
                'sam__ResizeXYZ.jpg',
                new ParsedFileID('sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'sam.jpg',
                new ParsedFileID('sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'folder/sam__ResizeXYZ.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'folder/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'folder/sam__ResizeXYZ.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890', 'ResizeXXX'),
                true
            ],
            [
                'folder/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890', 'ResizeXXX'),
                true
            ],
            [
                'sam__ResizeXYZ.jpg',
                new ParsedFileID('wrong-folder/sam.jpg', 'abcdef7890'),
                false
            ],
            [
                'folder/sam__ResizeXYZ.jpg',
                new ParsedFileID('wrong-file-name.jpg', 'abcdef7890'),
                false
            ],
            [
                'folder/abcdef7890/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890'),
                false
            ],
        ];
    }

    public static function variantIn()
    {
        return [
            [new ParsedFileID('sam.jpg'), ''],
            [new ParsedFileID('folder/sam.jpg'), 'folder'],
            [new ParsedFileID('sam.jpg', 'abcdef7890'), ''],
            [new ParsedFileID('folder/sam.jpg', 'abcdef7890'), 'folder'],
            [new ParsedFileID('folder/sam.jpg', 'abcdef7890'), 'folder'],
            [new ParsedFileID('folder/sam.jpg', 'abcdef7890', 'ResizeXXX'), 'folder'],
        ];
    }

    public static function provideGetVariantGlob(): array
    {
        return [
            'folder is excluded' => [
                'folder' => 'my-folder',
                'parsedFileID' => new ParsedFileID('my-folder/my-file.jpg', '123456789'),
                'expected' => 'my-file__*',
            ],
            'hash is not accounted for' => [
                'folder' => 'my-folder/123456789',
                'parsedFileID' => new ParsedFileID('my-folder/my-file.jpg', '123456789'),
                'expected' => 'my-folder/my-file__*',
            ],
            'full folder path is excluded' => [
                'folder' => 'my-folder/sub-folder',
                'parsedFileID' => new ParsedFileID('my-folder/sub-folder/my-file.jpg', '123456789'),
                'expected' => 'my-file__*',
            ],
            'different folder gets ignored' => [
                'folder' => 'different-folder/123456789',
                'parsedFileID' => new ParsedFileID('my-folder/my-file.jpg', '123456789'),
                'expected' => 'my-folder/my-file__*',
            ],
        ];
    }

    #[DataProvider('provideGetVariantGlob')]
    public function testGetVariantGlob(string $folder, ParsedFileID $parsedFileID, string $expected): void
    {
        $helper = new NaturalFileIDHelper();
        $glob = $helper->getVariantGlob($folder, $parsedFileID);
        $this->assertSame($expected, $glob);
    }
}

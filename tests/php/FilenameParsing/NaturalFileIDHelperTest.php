<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;

class NaturalFileIDHelperTest extends FileIDHelperTester
{

    protected function getHelper()
    {
        return new NaturalFileIDHelper();
    }

    public function fileIDComponents()
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

    public function dirtyFileIDComponents()
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
            ['sam_double-under-score__resizeXYZ.jpg', [
                'sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/sam_double-under-score__resizeXYZ.jpg', [
                'subfolder/sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
        ];
    }

    public function dirtyFileIDFromDirtyTuple()
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
        ];
    }

    public function brokenFileID()
    {
        return [
            ['/sam.jpg'],
            ['/no-slash-start/sam__resizeXYZ.jpg'],
            ['folder//sam.jpg']
        ];
    }

    public function variantOf()
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

    public function variantIn()
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
}

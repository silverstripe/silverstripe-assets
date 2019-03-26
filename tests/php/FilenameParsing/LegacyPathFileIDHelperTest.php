<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;

class LegacyFileIDHelperTest extends FileIDHelperTester
{

    protected function getHelper()
    {
        return new LegacyFileIDHelper();
    }

    public function fileIDComponents()
    {
        return [
            // Common use case
            ['sam.jpg', ['sam.jpg', '']],
            ['subfolder/sam.jpg', ['subfolder/sam.jpg', '']],
            ['subfolder/_resampled/resizeXYZ/sam.jpg', ['subfolder/sam.jpg', '', 'resizeXYZ']],
            ['_resampled/resizeXYZ/sam.jpg', ['sam.jpg', '', 'resizeXYZ']],
            // Edge casey scenario
            ['subfolder/under_score/_resampled/resizeXYZ/sam.jpg', [
                'subfolder/under_score/sam.jpg', '', 'resizeXYZ'
            ]],
            ['subfolder/under_score/_resampled/resizeXYZ/sam_single-underscore.jpg', [
                'subfolder/under_score/sam_single-underscore.jpg', '', 'resizeXYZ'
            ]],
            ['subfolder/under_score/sam_double_dots.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', ''
            ]],
            ['subfolder/under_score/_resampled/resizeXYZ/sam_double_dots.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', '', 'resizeXYZ'
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
            ['sam__double-under-score.jpg', [
                'sam__double-under-score.jpg', ''
            ]],
            ['_resampled/resizeXYZ/sam__double-under-score.jpg', [
                'sam__double-under-score.jpg', '', 'resizeXYZ'
            ]],
            ['subfolder/_resampled/resizeXYZ/sam__double-under-score.jpg', [
                'subfolder/sam__double-under-score.jpg', '', 'resizeXYZ'
            ]],
            ['_resampled/resizeXYZ/sam__double-under-score.jpg', [
                'sam__double-under-score.jpg', 'abcdef7890', 'resizeXYZ'
            ]],
            ['subfolder/_resampled/resizeXYZ/sam__double-under-score.jpg', [
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
            ['sub_folder/double__underscore.jpg', 'sub_folder/double__underscore.jpg'],
            ['sub_folder/single_underscore.jpg', 'sub_folder/single_underscore.jpg'],
        ];
    }

    public function brokenFileID()
    {
        return [
            ['/sam.jpg'],
            ['/no-slash-start/sam__resizeXYZ.jpg'],
            ['folder//sam.jpg'],
            // We need newer format to fail on legacy
            ['sam__resizeXYZ.jpg'],
            ['subfolder/sam__resizeXYZ.jpg'],
            ['abcdef7890/sam__resizeXYZ.jpg'],
            ['subfolder/abcdef7890/sam__resizeXYZ.jpg'],
        ];
    }

    public function variantOf()
    {
        return [
            [
                '_resampled/ResizeXYZ/sam.jpg',
                new ParsedFileID('sam.jpg'),
                true
            ],
            [
                'sam.jpg',
                new ParsedFileID('sam.jpg'),
                true
            ],
            [
                'folder/_resampled/ResizeXYZ/sam.jpg',
                new ParsedFileID('folder/sam.jpg'),
                true
            ],
            [
                'folder/sam.jpg',
                new ParsedFileID('folder/sam.jpg'),
                true
            ],
            [
                'folder/_resampled/ResizeXYZ/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'folder/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890'),
                true
            ],
            [
                'folder/_resampled/ResizeXYZ/sam.jpg',
                new ParsedFileID('folder/sam.jpg', '', 'ResizeXXX'),
                true
            ],
            [
                'folder/sam.jpg',
                new ParsedFileID('folder/sam.jpg', '', 'ResizeXXX' ),
                true
            ],
            [
                'folder/_resampled/ResizeXYZ/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890', 'ResizeXXX'),
                true
            ],
            [
                'folder/sam.jpg',
                new ParsedFileID('folder/sam.jpg', 'abcdef7890', 'ResizeXXX'),
                true
            ],
            [
                'folder/sam.jpg',
                new ParsedFileID('wrong-folder/sam.jpg', 'abcdef7890'),
                false
            ],
            [
                'folder/sam.jpg',
                new ParsedFileID('wrong-file-name.jpg', 'folder'),
                false
            ],
            [
                'folder/_resampled/ResizeXYZ/sam.jpg',
                new ParsedFileID('wrong-folder/sam.jpg', 'abcdef7890'),
                false
            ],
            [
                'folder/_resampled/ResizeXYZ/sam.jpg',
                new ParsedFileID('wrong-file-name.jpg', 'folder'),
                false
            ],
        ];
    }

    public function variantIn()
    {
        return [
            [new ParsedFileID('sam.jpg', 'abcdef7890'), '_resampled'],
            [new ParsedFileID('folder/sam.jpg', 'abcdef7890'), 'folder/_resampled'],
            [new ParsedFileID('sam.jpg', 'abcdef7890'), '_resampled'],
            [new ParsedFileID('folder/sam.jpg', 'abcdef7890'), 'folder/_resampled'],
            [new ParsedFileID('folder/truncate-hash.jpg', 'abcdef78901'), 'folder/_resampled'],
            [new ParsedFileID('folder/truncate-hash.jpg', 'abcdef7890', 'ResizeXXX'), 'folder/_resampled'],
        ];
    }
}

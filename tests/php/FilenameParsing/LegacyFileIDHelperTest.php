<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Dev\Deprecation;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;

class LegacyFileIDHelperTest extends FileIDHelperTester
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
    }

    protected function getHelper()
    {
        return new LegacyFileIDHelper();
    }

    public function fileIDComponents()
    {
        return [
            // Common use case
            'simple file' => ['sam.jpg', ['sam.jpg', '']],
            'file in folder' => ['subfolder/sam.jpg', ['subfolder/sam.jpg', '']],
            'single variant' => ['subfolder/_resampled/resizeXYZ/sam.jpg', ['subfolder/sam.jpg', '', 'resizeXYZ']],
            'multi variant' => ['subfolder/_resampled/resizeXYZ/scaleheightABC/sam.jpg',
                ['subfolder/sam.jpg', '', 'resizeXYZ_scaleheightABC']],
            'root single variant' => ['_resampled/resizeXYZ/sam.jpg', ['sam.jpg', '', 'resizeXYZ']],
            'root multi variant' => ['_resampled/resizeXYZ/scaleheightABC/sam.jpg', ['sam.jpg', '', 'resizeXYZ_scaleheightABC']],
            // Edge casey scenario
            'folder with underscore' => ['subfolder/under_score/_resampled/resizeXYZ/sam.jpg', [
                'subfolder/under_score/sam.jpg', '', 'resizeXYZ'
            ]],
            'filename with underscore' => ['subfolder/under_score/_resampled/resizeXYZ/sam_single-underscore.jpg', [
                'subfolder/under_score/sam_single-underscore.jpg', '', 'resizeXYZ'
            ]],
            'filename with multi underscore' => ['subfolder/under_score/sam_double_dots.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', ''
            ]],
            'double dot file name' => ['subfolder/under_score/_resampled/resizeXYZ/sam_double_dots.tar.gz', [
                'subfolder/under_score/sam_double_dots.tar.gz', '', 'resizeXYZ'
            ]],
            'stack variant' => ['subfolder/under_score/_resampled/stack/variant/sam_double_dots.tar.gz', [
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

    public function dirtyFileIDFromDirtyTuple()
    {
        // Legacy FileID helper doesn't do any cleaning, so we can reuse dirtyFileIDComponents
        return $this->dirtyFileIDComponents();
    }

    function dirtyFilenames()
    {
        return [
            ['sam.jpg', 'sam.jpg'],
            ['subfolder/sam.jpg', 'subfolder/sam.jpg'],
            ['sub_folder/sam.jpg', 'sub_folder/sam.jpg'],
            ['sub_folder/double__underscore.jpg', 'sub_folder/double__underscore.jpg'],
            ['sub_folder/single_underscore.jpg', 'sub_folder/single_underscore.jpg'],
            ['Folder/With/Backslash/file.jpg', 'Folder\With\Backslash\file.jpg'],
        ];
    }

    public function brokenFileID()
    {
        return [
            ['/sam.jpg'],
            ['/no-slash-start/sam__resizeXYZ.jpg'],
            ['folder//sam.jpg'],
            // Can't have an image directly in a _resampled folder without a variant
            ['_resampled/sam.jpg'],
            ['folder/_resampled/sam.jpg'],
            ['folder/_resampled/padWw-sam.jpg'],
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
                '_resampled/InvalidMethodXYZ-sam.jpg',
                new ParsedFileID('sam.jpg'),
                false
            ],
            [
                '_resampled/PadW10-sam.jpg',
                new ParsedFileID('sam.jpg'),
                true
            ],
            [
                '_resampled/PadW10-CmsThumbnailW10-sam.jpg',
                new ParsedFileID('sam.jpg'),
                true
            ],
            [
                '_resampled/ResizeXYZ-sam.jpg',
                new ParsedFileID('sam.jpg'),
                false
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
                new ParsedFileID('folder/sam.jpg', '', 'ResizeXXX'),
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

    /**
     * SS 3.0 to SS3.2 was using a different format for variant
     * @return array
     */
    public function ss30FileIDs()
    {
        return [
            'single SS30 variant' => ['subfolder/_resampled/FitW10-sam.jpg', ['subfolder/sam.jpg', '', 'FitW10']],
            'multi SS30 variant' => ['subfolder/_resampled/FitWzEwMjQsMTAwXQ-PadW10-sam.jpg',
                ['subfolder/sam.jpg', '', 'FitWzEwMjQsMTAwXQ_PadW10']],
            'SS30 variant filename starting with variant method' =>
                ['subfolder/_resampled/FitW10-padding-sam.jpg', ['subfolder/padding-sam.jpg', '', 'FitW10']],
            'SS30 variant filename starting with an ambiguous variant method' =>
                ['_resampled/PaddedImageW10-sam.jpg', ['sam.jpg', '', 'PaddedImageW10']],
        ];
    }

    /**
     * @dataProvider fileIDComponents
     * @dataProvider ss30FileIDs
     */
    public function testParseFileID($input, $expected)
    {
        parent::testParseFileID($input, $expected);
    }
}

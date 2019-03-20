<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\LegacyPathFileIDHelper;

class LegacyPathFileIDHelperTest extends FileIDHelperTester
{

    protected function getHelper()
    {
        return new LegacyPathFileIDHelper();
    }

    /**
     * List of valid file IDs and their matching component. The first parameter can be use the deduc the second, and
     * the second can be used to build the first.
     * @return array
     */
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

    /**
     * List of unclean buildFileID inputs and their expected output. Second parameter can build the first, but not the
     * other way around.
     * @return array
     */
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

    /**
     * List of potentially dirty filename and their clean equivalent
     * @return array
     */
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

    /**
     * List of broken file ID that will break the hash parser regex.
     */
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

}
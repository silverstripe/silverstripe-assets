<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;

class HashFileIDHelperTest extends FileIDHelperTester
{

    protected function getHelper()
    {
        return new HashFileIDHelper();
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
            ['sub_folder/double_underscore.jpg', 'sub_folder/double__underscore.jpg'],
            ['sub_folder/single_underscore.jpg', 'sub_folder/single_underscore.jpg'],
        ];
    }

    /**
     * List of broken file ID that will break the hash parser regex.
     */
    public function brokenFileID()
    {
        return [
            ['sam.jpg'],
            ['sam__resizeXYZ.jpg'],
            ['folder//sam.jpg'],
            ['folder/not10characters/sam.jpg'],
        ];
    }
}

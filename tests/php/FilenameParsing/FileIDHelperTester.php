<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Dev\SapphireTest;

/**
 * All the `FileIDHelper` have the exact same signature and very similar structure. Their basic tests will share the
 * same structure.
 */
abstract class FileIDHelperTester extends SapphireTest
{

    /**
     * @return FileIDHelper
     */
    abstract protected static function getHelper();

    /**
     * List of valid file IDs and their matching component. The first parameter can be use the deduc the second, and
     * the second can be used to build the first.
     * @return array
     */
    abstract public static function fileIDComponents();

    /**
     * List of unclean buildFileID inputs and their expected output. Second parameter can build the first, but not the
     * other way around.
     * @return array
     */
    abstract public static function dirtyFileIDComponents();

    /**
     * Similar to `dirtyFileIDComponents` only the expected output is dirty has well.
     * @return array
     */
    abstract public static function dirtyFileIDFromDirtyTuple();

    /**
     * List of potentially dirty filename and their clean equivalent
     * @return array
     */
    abstract public static function dirtyFilenames();

    /**
     * List of broken file ID that will break the hash parser regex.
     */
    abstract public static function brokenFileID();

    /**
     * List of `fileID` and `original` parsedFileID and whatever the `fileID` is a variant of `original`
     * @return array[]
     */
    abstract public static function variantOf();

    /**
     * List of parsedFieldID and a matching expected path where its variants should be search for.
     * @return array[]
     */
    abstract public static function variantIn();

    #[DataProvider('fileIDComponents')]
    #[DataProvider('dirtyFileIDComponents')]
    public function testBuildFileID($expected, $input)
    {
        $help = $this->getHelper();
        $this->assertEquals($expected, $help->buildFileID(...$input));
        $this->assertEquals($expected, $help->buildFileID(new ParsedFileID(...$input)));
    }

    /**
     * `buildFileID` accepts an optional `cleanFilename` argument that disables cleaning of filename.
     */
    #[DataProvider('dirtyFileIDFromDirtyTuple')]
    #[DataProvider('fileIDComponents')]
    public function testDirtyBuildFildID($expected, $input)
    {
        $help = $this->getHelper();
        $this->assertEquals($expected, $help->buildFileID(new ParsedFileID(...$input), null, null, false));
    }

    #[DataProvider('dirtyFilenames')]
    public function testCleanFilename($expected, $input)
    {
        $help = $this->getHelper();
        $this->assertEquals($expected, $help->cleanFilename($input));
    }

    #[DataProvider('fileIDComponents')]
    public function testParseFileID($input, $expected)
    {
        $help = $this->getHelper();
        $parsedFiledID = $help->parseFileID($input);

        list($expectedFilename, $expectedHash) = $expected;
        $expectedVariant = isset($expected[2]) ? $expected[2] : '';

        $this->assertNotNull($parsedFiledID);
        $this->assertEquals($input, $parsedFiledID->getFileID());
        $this->assertEquals($expectedFilename, $parsedFiledID->getFilename());
        $this->assertEquals($expectedHash, $parsedFiledID->getHash());
        $this->assertEquals($expectedVariant, $parsedFiledID->getVariant());
    }

    #[DataProvider('brokenFileID')]
    public function testParseBrokenFileID($input)
    {
        $help = $this->getHelper();
        $parsedFiledID = $help->parseFileID($input);
        $this->assertNull($parsedFiledID);
    }

    #[DataProvider('variantOf')]
    public function testVariantOf($variantFileID, ParsedFileID $original, $expected)
    {
        $help = $this->getHelper();
        $isVariantOf = $help->isVariantOf($variantFileID, $original);
        $this->assertEquals($expected, $isVariantOf);
    }

    #[DataProvider('variantIn')]
    public function testLookForVariantIn(ParsedFileID $original, $expected)
    {
        $help = $this->getHelper();
        $path = $help->lookForVariantIn($original);
        $this->assertEquals($expected, $path);
    }
}

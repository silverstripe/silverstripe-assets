<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Dev\TestOnly;

/**
 * Mock FileIDHelper that always return the same values all the time as defined in the constructor
 */
class MockFileIDHelper implements TestOnly, FileIDHelper
{

    public $fileID;
    public $filename;
    public $hash;
    public $variant;
    public $isVariantOfVal;
    public $lookForVariantInVal;

    public function __construct($filename, $hash, $variant, $fileID, $isVariantOf, $lookForVariantIn)
    {
        $this->fileID = $fileID;
        $this->filename = $filename;
        $this->hash = $hash;
        $this->variant = $variant;
        $this->isVariantOfVal = $isVariantOf;
        $this->lookForVariantInVal = $lookForVariantIn;
    }

    public function buildFileID($filename, $hash = null, $variant = null, $cleanFilename = true)
    {
        return $this->fileID;
    }

    public function cleanFilename($filename)
    {
        return $this->filename;
    }

    public function parseFileID($fileID)
    {
        return new ParsedFileID(
            $this->filename,
            $this->hash,
            $this->variant,
            $fileID
        );
    }

    public function isVariantOf($fileID, ParsedFileID $parsedFileID)
    {
        return $this->isVariantOfVal;
    }

    public function lookForVariantIn(ParsedFileID $parsedFileID)
    {
        return $this->lookForVariantInVal;
    }

    public function lookForVariantRecursive(): bool
    {
        return true;
    }
}

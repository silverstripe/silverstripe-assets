<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Dev\TestOnly;

/**
 * Mock FileIDHelper that always return the same values all the time as defined in the constructor
 */
class BrokenFileIDHelper extends MockFileIDHelper implements TestOnly
{
    public function parseFileID($fileID)
    {
        return null;
    }
}

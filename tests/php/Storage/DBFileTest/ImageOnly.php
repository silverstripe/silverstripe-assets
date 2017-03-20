<?php

namespace SilverStripe\Assets\Tests\Storage\DBFileTest;

use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property DBFile $MyFile
 */
class ImageOnly extends DataObject implements TestOnly
{
    private static $table_name = 'DBFileTest_ImageOnly';

    private static $db = array(
        "MyFile" => "DBFile('image/supported')"
    );
}

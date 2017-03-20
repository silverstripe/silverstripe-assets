<?php

namespace SilverStripe\Assets\Tests\Storage\DBFileTest;

use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Assets\Tests\Storage\DBFileTest\TestObject;

/**
 * @property DBFile $AnotherFile
 */
class Subclass extends TestObject implements TestOnly
{
    private static $table_name = 'DBFileTest_Subclass';

    private static $db = array(
        "AnotherFile" => "DBFile"
    );
}

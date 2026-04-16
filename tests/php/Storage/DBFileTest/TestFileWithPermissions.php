<?php

namespace SilverStripe\Assets\Tests\Storage\DBFileTest;

use SilverStripe\Assets\File;
use SilverStripe\Core\Resettable;
use SilverStripe\Dev\TestOnly;

class TestFileWithPermissions extends File implements TestOnly, Resettable
{
    private static $table_name = 'DBFileTest_TestFileWithPermissions';

    public static bool $canView = true;

    public static function reset()
    {
        static::$canView = true;
        parent::reset();
    }

    public function canView($member = null)
    {
        return static::$canView;
    }
}

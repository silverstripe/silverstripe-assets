<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use SilverStripe\Assets\Folder;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\Tasks\FixFolderPermissionsHelper;

/**
 * Ensures that files with invalid permissions can be fixed.
 */
class FixFolderPermissionsHelperTest extends SapphireTest
{
    protected static $fixture_file = 'FixFolderPermissionsHelperTest.yml';

    public function testTask()
    {
        $task = new FixFolderPermissionsHelper();
        $updated = $task->run();

        $this->assertEquals('Inherit', Folder::get()->filter('Name', 'ParentFolder')->first()->CanViewType);
        $this->assertEquals('Anyone', Folder::get()->filter('Name', 'SubFolder')->first()->CanViewType);
        $this->assertEquals('Inherit', Folder::get()->filter('Name', 'AnotherFolder')->first()->CanViewType);
        $this->assertEquals(2, $updated);
    }
}

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

        $this->assertEquals('Inherit', Folder::get()->where(['Name' => 'ParentFolder'])->first()->CanViewType);
        $this->assertEquals('Anyone', Folder::get()->where(['Name' => 'SubFolder'])->first()->CanViewType);
        $this->assertEquals('Inherit', Folder::get()->where(['Name' => 'AnotherFolder'])->first()->CanViewType);
        $this->assertEquals(2, $updated);
    }
}

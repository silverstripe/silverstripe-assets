<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Folder;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\Tasks\FixFilePermissionsHelper;

/**
 * Ensures that files with invalid permissions can be fixed.
 */
class FixFilePermissionsTest extends SapphireTest
{
    protected static $fixture_file = 'FixFilePermissionsTest.yml';

    public function testTask()
    {
        $fixFilePermissionsTask = new FixFilePermissionsHelper();
        $updated = $fixFilePermissionsTask->run();

        $this->assertEquals('Inherit', Folder::get()->where(['Name' => 'ParentFolder'])->first()->CanViewType);
        $this->assertEquals('Anyone', Folder::get()->where(['Name' => 'SubFolder'])->first()->CanViewType);
        $this->assertEquals('Inherit', Folder::get()->where(['Name' => 'AnotherFolder'])->first()->CanViewType);
        $this->assertEquals(2, $updated);
    }
}

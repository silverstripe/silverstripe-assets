<?php

namespace SilverStripe\Assets\Tests\Shortcodes;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Tests\Shortcodes\FileBrokenLinksTest\EditableObject;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * Tests broken links in html areas
 */
class FileBrokenLinksTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        EditableObject::class,
    ];

    public function testDeletingFileMarksBackedPagesAsBroken()
    {
        // Test entry
        $file = new File();
        $file->setFromString('test', 'test-file.txt');
        $file->write();

        // Parent object
        $obj = new EditableObject();
        $obj->Content = sprintf(
            '<p><a href="[file_link,id=%d]">Working Link</a></p>',
            $file->ID
        );
        $obj->write();
        $this->assertTrue($obj->publishRecursive());
        // Confirm that it isn't marked as broken to begin with

        // File initially contains one link
        $this->assertCount(1, $file->BackLinks());

        /** @var EditableObject $obj */
        $obj = EditableObject::get()->byID($obj->ID);
        $this->assertEquals(0, $obj->HasBrokenFile);

        /** @var EditableObject $liveObj */
        $liveObj = Versioned::get_by_stage(EditableObject::class, Versioned::LIVE)->byID($obj->ID);
        $this->assertEquals(0, $liveObj->HasBrokenFile);

        // Delete the file
        $file->delete();

        // Confirm that it is marked as broken in stage
        $obj = EditableObject::get()->byID($obj->ID);
        $this->assertEquals(1, $obj->HasBrokenFile);

        // Publishing this page marks it as broken on live too
        $obj->publishRecursive();
        $liveObj = Versioned::get_by_stage(EditableObject::class, Versioned::LIVE)->byID($obj->ID);
        $this->assertEquals(1, $liveObj->HasBrokenFile);
    }
}

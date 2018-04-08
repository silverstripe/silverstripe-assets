<?php

namespace SilverStripe\Assets\Tests\Shortcodes\FileBrokenLinksTest;

use SilverStripe\Assets\Shortcodes\FileLinkTracking;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\RecursivePublishable;
use SilverStripe\Versioned\Versioned;

/**
 * @mixin RecursivePublishable
 * @mixin FileLinkTracking
 * @mixin Versioned
 * @property string $Content
 * @property string $Another
 * @property bool $HasBrokenFile
 */
class EditableObject extends DataObject implements TestOnly
{
    private static $table_name = 'FileBrokenLinksTest_EditableObject';

    private static $db = [
        'Title' => 'Varchar',
        'Content' => 'HTMLText',
        'Another' => 'HTMLText',
        'HasBrokenFile' => 'Boolean',
    ];

    private static $extensions = [
        Versioned::class,
    ];
}

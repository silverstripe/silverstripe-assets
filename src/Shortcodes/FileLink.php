<?php

namespace SilverStripe\Assets\Shortcodes;

use SilverStripe\Assets\File;
use SilverStripe\ORM\DataObject;

/**
 * Represents a link between a dataobject parent and a file shortcode in a HTML content area
 *
 * @method DataObject Parent() Parent object
 * @method File Linked() File being linked to
 */
class FileLink extends DataObject
{
    private static $table_name = 'FileLink';

    private static $owns = [
        'Linked',
    ];

    private static $owned_by = [
        'Parent',
    ];

    private static $has_one = [
        'Parent' => DataObject::class,
        'Linked' => File::class,
    ];
}

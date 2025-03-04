<?php

namespace SilverStripe\Assets\Shortcodes;

use SilverStripe\Assets\File;
use SilverStripe\ORM\DataObject;

/**
 * Represents a link between a dataobject parent and a file shortcode in a HTML content area
 *
 * @method File Linked()
 * @method DataObject Parent()
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

    /**
     * Don't show this model in campaign admin as part of implicit change sets
     *
     * @config
     * @var bool
     */
    private static $hide_in_campaigns = true;
}

<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks\Shortcode;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

class PseudoPage extends DataObject implements TestOnly
{
    private static $db = [
        'Title' => 'Varchar',
        'Content' => 'HTMLText',
    ];

    private static $extensions = [
        Versioned::class,
    ];

    private static $table_name = 'TagsToShortCodeHelperTest_PseudoPage';
}
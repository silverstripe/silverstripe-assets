<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks\Shortcode;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

class NoStage extends DataObject implements TestOnly
{

    private static $extensions = [
        Versioned::class . '.versioned',
    ];

    private static $db = [
        'Content' => 'HTMLText'
    ];
}

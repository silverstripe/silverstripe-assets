<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks\Shortcode;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class SubHtmlObject extends HtmlObject implements TestOnly
{

    private static $db = [
        'HtmlContent' => 'HTMLText'
    ];

}

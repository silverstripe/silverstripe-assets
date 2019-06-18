<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks\Shortcode;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class HtmlObject extends DataObject implements TestOnly
{

    private static $db = [
        'HtmlLine' => 'HTMLVarchar(1024,array("shortcodes"=>true))',
        'HtmlLineNoShortCode' => 'HTMLVarchar(1024)',
        'Content' => 'HTMLText',
        'ContentNoShortCode' => 'HTMLText(array("shortcodes"=>false))'
    ];
}

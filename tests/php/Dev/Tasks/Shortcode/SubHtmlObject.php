<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks\Shortcode;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;

class SubHtmlObject extends HtmlObject implements TestOnly
{

    private static $db = [
        'HtmlContent' => DBHTMLText::class . '(["shortcodes"=>true])'
    ];
}

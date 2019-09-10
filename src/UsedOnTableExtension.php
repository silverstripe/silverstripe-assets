<?php

namespace SilverStripe\Assets;


use SilverStripe\Assets\Shortcodes\FileLink;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;

/**
 * Class UsedOnTableExtension
 *
 * Hides File Links on the Used On tab when viewing files
 */
class UsedOnTableExtension extends Extension
{
    public function updateUsage(ArrayList &$usage, DataObject &$record) {
        $usage = $usage->filterByCallback(function($userId, $user) {
            return $userId != FileLink::class;
        });
    }
}

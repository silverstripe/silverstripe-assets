<?php

namespace SilverStripe\Assets\Dev\Tasks;

use SilverStripe\Dev\BuildTask;

/**
 * Class TagsToShortcodeTask
 * @package SilverStripe\Assets\Dev\Tasks
 */
class TagsToShortcodeTask extends BuildTask
{
    private static $segment = 'TagsToShortcode';

    protected $title = 'Rewrite tags to shortcodes';

    protected $description = "Rewrites tags to shortcodes in any HTMLText field";

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     * @throws \ReflectionException
     */
    public function run($request)
    {
        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $tagsToShortcodeHelper->run();

        echo 'DONE';
    }
}

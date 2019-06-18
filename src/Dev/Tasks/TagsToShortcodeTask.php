<?php

namespace SilverStripe\Assets\Dev\Tasks;

use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;

/**
 * SS4 and its File Migration Task changes the way in which files are stored in the assets folder, with files placed
 * in subfolders named with partial hashmap values of the file version. This build task goes through the HTML content
 * fields looking for instances of image links, and corrects the link path to what it should be, with an image shortcode.
 */
class TagsToShortcodeTask extends BuildTask
{
    private static $segment = 'TagsToShortcode';

    protected $title = 'Rewrite tags to shortcodes';

    protected $description = "
        Rewrites tags to shortcodes in any HTMLText field

		Parameters:
		- baseClass: The base class that will be used to look up HTMLText fields. Defaults to SilverStripe\ORM\DataObject
		- includeBaseClass: Whether to include the base class' HTMLText fields or not
    ";

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     * @throws \ReflectionException
     */
    public function run($request)
    {
        Injector::inst()->get(FileHashingService::class)->enableCache();

        $tagsToShortcodeHelper = new TagsToShortcodeHelper(
            $request->getVar('baseClass'),
            isset($request->getVars()['includeBaseClass'])
        );
        $tagsToShortcodeHelper->run();

        echo 'DONE';
    }
}

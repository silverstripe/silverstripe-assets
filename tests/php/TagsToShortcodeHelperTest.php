<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Dev\Tasks\TagsToShortcodeHelper;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

class TagsToShortcodeHelperTest extends SapphireTest
{
    protected static $fixture_file = 'TagsToShortcodeHelperTest.yml';

    public function testRewrite()
    {
        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $tagsToShortcodeHelper->run();

        /** @var SiteTree $newPage */
        $newPage = SiteTree::get()->first();

        self::assertEquals(<<<HTML
 <p>this needs to be rewritten: <a href="[file_link id=2]">link to file</a></p> <p>and so does this: [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p>
<p>but not this: <a href="[file_link id=2]" class="ss-broken">link to file</a></p> <p>and neither this: <a href="/assets/invalid_document.pdf">link to file</a></p> 
HTML
            , $newPage->Content, 'Content is not correct');
    }
}

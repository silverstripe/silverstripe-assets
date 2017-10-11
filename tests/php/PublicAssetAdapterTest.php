<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Control\Director;
use SilverStripe\Dev\SapphireTest;

class PublicAssetAdapterTest extends SapphireTest
{
    protected function setUp()
    {
        parent::setUp();
        Director::config()->set(
            'alternate_base_url',
            'http://www.mysitem.com/baseurl/'
        );
    }

    public function testInitBaseURL()
    {
        // Test windows paths generate correct url
        // TODO Fix Filesystem::makeFolder() to use realpath, otherwise this fails in AssetAdapter::__construct()
        // $base = str_replace('/', '\\', BASE_PATH) . '\\assets\\subdir';
        $base = BASE_PATH . '/assets/subdir';
        $adapter = new PublicAssetAdapter($base);

        $this->assertEquals(
            '/baseurl/assets/subdir/dir/file.jpg',
            $adapter->getPublicUrl('dir/file.jpg')
        );
    }
}

<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\ProtectedFileController;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Assets\Tests\Storage\AssetStoreTest\TestAssetStore;

/**
 * We rerun all the same test in `RedirectFileControllerTest` but with keep_archived_assets on
 * @skipUpgrade
 */
class RedirectKeepArchiveFileControllerTest extends RedirectFileControllerTest
{

    public function setUp()
    {
        File::config()->set('keep_archived_assets', true);
        parent::setUp();
    }

    public function testRedirectAfterUnpublish()
    {
        $file = File::find('FileTest-subfolder/FileTestSubfolder.txt');
        $file->publishSingle();
        $v1hash = $file->getHash();
        $v1Url = $file->getURL(false);

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $v2Url = $file->getURL(false);

        $file->publishSingle();

        $this->getAssetStore()->grant('FileTest-subfolder/FileTestSubfolder.txt', $v1hash);
        $response = $this->get($v1Url);
        $this->getAssetStore()->revoke('FileTest-subfolder/FileTestSubfolder.txt', $v1hash);
        $this->assertResponse(
            200,
            str_repeat('x', 1000000),
            false,
            $response,
            'Old Hash URL of live file should return 200 when access is granted'
        );

        $file->doUnpublish();

        // After unpublishing file
        $response = $this->get($v2Url);
        $this->assertResponse(
            403,
            '',
            false,
            $response,
            'Unpublish file should return 403'
        );

        $response = $this->get('/assets/FileTest-subfolder/FileTestSubfolder.txt');
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Legacy URL of unpublish files should return 404'
        );

        $response = $this->get($v1Url);
        $this->assertResponse(
            403,
            '',
            false,
            $response,
            'Old Hash URL of unpublished files should return 403'
        );

        $this->getAssetStore()->grant('FileTest-subfolder/FileTestSubfolder.txt', $v1hash);
        $response = $this->get($v1Url);
        $this->assertResponse(
            200,
            str_repeat('x', 1000000),
            false,
            $response,
            'Old Hash URL of unpublished files should return 200 when access is granted'
        );
    }
}

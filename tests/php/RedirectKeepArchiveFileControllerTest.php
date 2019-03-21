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

    /**
     * @dataProvider fileList
     */
    public function testRedirectAfterUnpublish($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1hash = $file->getHash();
        $v1Url = $file->getURL(false);

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $v2Url = $file->getURL(false);

        $file->publishSingle();

        $this->getAssetStore()->grant($file->getFilename(), $v1hash);
        $response = $this->get($v1Url);
        $this->getAssetStore()->revoke($file->getFilename(), $v1hash);
        $this->assertResponse(
            200,
            'version 1',
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

        $response = $this->get('/assets/' . $file->getFilename());
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

        $this->getAssetStore()->grant($file->getFilename(), $v1hash);
        $response = $this->get($v1Url);
        $this->assertResponse(
            200,
            'version 1',
            false,
            $response,
            'Old Hash URL of unpublished files should return 200 when access is granted'
        );
    }

    /**
     * When keeping archives. The old files should still be there. So the protected adapter should deny you access to
     * them.
     *
     * @dataProvider fileList
     */
    public function testRedirectAfterDeleting($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1Url = $file->getURL(false);

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $file->publishSingle();
        $v2Url = $file->getURL(false);

        $file->doUnpublish();
        $file->delete();

        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Legacy URL of archived files should return 404'
        );

        $response = $this->get($v1Url);
        $this->assertResponse(
            403,
            '',
            false,
            $response,
            'Old Hash URL of archived files should return 403'
        );

        $response = $this->get($v2Url);
        $this->assertResponse(
            403,
            '',
            false,
            $response,
            'Archived file should return 403'
        );
    }

    /**
     * When keeping archives. The old files should still be there. So the protected adapter should deny you access to
     * them.
     *
     * @dataProvider fileList
     */
    public function testResolvedArchivedFile($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1Url = $file->getURL(false);
        $v1Hash = $file->getHash();

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $file->publishSingle();
        $v2Url = $file->getURL(false);
        $v2Hash = $file->getHash();

        $file->doUnpublish();
        $file->delete();

        $this->getAssetStore()->grant($file->getFilename(), $v1Hash);
        $this->getAssetStore()->grant($file->getFilename(), $v2Hash);

        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Legacy URL of archived files should return 404'
        );

        $response = $this->get($v1Url);
        $this->assertResponse(
            200,
            'version 1',
            false,
            $response,
            'Older versions of archived file should resolve when access is granted'
        );

        $response = $this->get($v2Url);
        $this->assertResponse(
            200,
            'version 2',
            false,
            $response,
            'Archived files should resolve when access is granted'
        );
    }
}

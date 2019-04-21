<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\File;

/**
 * We rerun all the same test in `RedirectFileControllerTest` but with keep_archived_assets on
 * @skipUpgrade
 */
class RedirectKeepArchiveFileControllerTest extends RedirectFileControllerTest
{

    protected function setUp()
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
}

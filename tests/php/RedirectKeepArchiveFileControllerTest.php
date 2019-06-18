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
        $v1HashUrl = $file->getURL(false);
        $v1hash = $file->getHash();
        $file->publishSingle();

        $file->setFromString(
            'version 2',
            $file->getFilename()
        );
        $file->write();
        $v2HashUrl = $file->getURL(false);
        
        $file->publishSingle();
        $v2Url = $file->getURL(false);

        $this->getAssetStore()->grant($file->getFilename(), $v1hash);
        $this->assertGetResponse(
            $v1HashUrl,
            200,
            'version 1',
            false,
            'Old Hash URL of live file should return 200 when access is granted'
        );
        $this->getAssetStore()->revoke($file->getFilename(), $v1hash);

        $file->doUnpublish();

        // After unpublishing file
        $this->assertGetResponse(
            $v2HashUrl,
            403,
            '',
            false,
            'Unpublish file should return 403'
        );

        $this->assertGetResponse(
            $v2Url,
            404,
            '',
            false,
            'Natural path URL of unpublish files should return 404'
        );

        $this->assertGetResponse(
            $v1HashUrl,
            403,
            '',
            false,
            'Old Hash URL of unpublished files should return 403'
        );

        $this->getAssetStore()->grant($file->getFilename(), $v1hash);
        $this->assertGetResponse(
            $v1HashUrl,
            200,
            'version 1',
            false,
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
        $v1HashUrl = $file->getURL(false);

        $file->publishSingle();

        $file->File->Hash = sha1('version 2');
        $file->setFromString(
            'version 2',
            $file->getFilename(),
            $file->getHash(),
            null,
            ['visibility' => AssetStore::VISIBILITY_PROTECTED]
        );

        $file->write();
        $v2HashUrl = $file->getURL(false);

        $file->publishSingle();

        $file->doUnpublish();

        $file->delete();

        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Natural Path URL of archived files should return 404'
        );
        $this->assertGetResponse(
            $v1HashUrl,
            403,
            '',
            false,
            'Old Hash URL of archived files should return 403'
        );

        $response = $this->get($v2HashUrl);
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
        $v1HashUrl = $file->getURL(false);
        $file->publishSingle();
        $v1Hash = $file->getHash();

        $file->File->Hash = sha1('version 2');
        $file->setFromString(
            'version 2',
            $file->getFilename(),
            $file->getHash(),
            null,
            ['visibility' => AssetStore::VISIBILITY_PROTECTED]
        );
        $file->write();
        $v2HashUrl = $file->getURL(false);
        $file->publishSingle();
        $v2Hash = $file->getHash();

        $file->doUnpublish();
        $file->delete();

        $this->getAssetStore()->grant($file->getFilename(), $v1Hash);
        $this->getAssetStore()->grant($file->getFilename(), $v2Hash);

        $this->assertGetResponse(
            $file->getFilename(),
            404,
            '',
            false,
            'Legacy URL of archived files should return 404'
        );

        $this->assertGetResponse(
            $v1HashUrl,
            200,
            'version 1',
            false,
            'Older versions of archived file should resolve when access is granted'
        );

        $this->assertGetResponse(
            $v2HashUrl,
            200,
            'version 2',
            false,
            'Archived files should resolve when access is granted'
        );
    }

    /**
     * @dataProvider imageList
     */
    public function testVariantRedirect($folderFixture, $filename, $ext)
    {
        parent::testVariantRedirect($folderFixture, $filename, $ext);
    }
}

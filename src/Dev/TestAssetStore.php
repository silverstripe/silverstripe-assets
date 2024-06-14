<?php

namespace SilverStripe\Assets\Dev;

use League\Flysystem\Visibility;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\Filesystem as SSFilesystem;
use SilverStripe\Assets\Flysystem\LocalFilesystemAdapter;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Flysystem\ProtectedAssetAdapter;
use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Flysystem\GeneratedAssets;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\AssetStoreRouter;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\Filesystem;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\GeneratedAssetHandler;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;

/**
 * Allows you to mock a backend store in a custom directory beneath assets.
 * Only to be used for mocking test fixtures
 */
class TestAssetStore extends FlysystemAssetStore implements TestOnly
{
    /**
     * Enable disclosure of secure assets
     *
     * @config
     * @var    int
     */
    private static $denied_response_code = 403;

    /**
     * Set to true|false to override all isSeekableStream calls
     *
     * @var null|bool
     */
    public static $seekable_override = null;

    /**
     * Base dir of current file
     *
     * @var string
     */
    public static $basedir = null;

    /**
     * Set this store as the new asset backend
     *
     * @param string $basedir Basedir to store assets, which will be placed beneath 'assets' folder
     */
    public static function activate($basedir)
    {
        // Assign this as the new store
        $publicAdapter = new PublicAssetAdapter(ASSETS_PATH . '/' . $basedir);
        $publicFilesystem = new Filesystem(
            $publicAdapter,
            [
                'visibility' => Visibility::PUBLIC
            ]
        );
        $protectedAdapter = new ProtectedAssetAdapter(ASSETS_PATH . '/' . $basedir . '/.protected');
        $protectedFilesystem = new Filesystem(
            $protectedAdapter,
            [
                'visibility' => Visibility::PRIVATE
            ]
        );

        $backend = new TestAssetStore();
        $backend->setPublicFilesystem($publicFilesystem);
        $backend->setProtectedFilesystem($protectedFilesystem);
        $backend->setPublicResolutionStrategy(Injector::inst()->get(FileResolutionStrategy::class . '.public'));
        $backend->setProtectedResolutionStrategy(Injector::inst()->get(FileResolutionStrategy::class . '.protected'));
        Injector::inst()->registerService($backend, AssetStore::class);
        Injector::inst()->registerService($backend, AssetStoreRouter::class);

        // Assign flysystem backend to generated asset handler at the same time
        $generated = new GeneratedAssets();
        $generated->setFilesystem($publicFilesystem);
        Injector::inst()->registerService($generated, GeneratedAssetHandler::class);
        Requirements::backend()->setAssetHandler($generated);

        // Set defaults
        Director::config()->set('alternate_base_url', '/');
        DBFile::config()->set('force_resample', false);
        File::config()->set('force_resample', false);
        TestAssetStore::reset();
        TestAssetStore::$basedir = $basedir;

        // Ensure basedir exists
        SSFilesystem::makeFolder(TestAssetStore::base_path());
    }

    /**
     * Get absolute path to basedir
     *
     * @return string
     */
    public static function base_path()
    {
        if (!TestAssetStore::$basedir) {
            return null;
        }
        return ASSETS_PATH . '/' . TestAssetStore::$basedir;
    }

    /**
     * Reset defaults for this store
     */
    public static function reset()
    {
        // Remove all files in this store
        if (TestAssetStore::$basedir) {
            $path = TestAssetStore::base_path();
            if (file_exists($path ?? '')) {
                SSFilesystem::removeFolder($path);
            }
        }
        TestAssetStore::$seekable_override = null;
        TestAssetStore::$basedir = null;
    }

    /**
     * Helper method to get local filesystem path for this file
     *
     * @param AssetContainer $asset
     * @param boolean $forceProtected
     * @param boolean $relative Return path relative to asset store root.
     * @return string
     */
    public static function getLocalPath(AssetContainer $asset, $forceProtected = false, $relative = false)
    {
        if ($asset instanceof Folder) {
            return TestAssetStore::base_path() . '/' . $asset->getFilename();
        }
        if ($asset instanceof File) {
            $asset = $asset->File;
        }
        // Extract filesystem used to store this object
        /** @var TestAssetStore $assetStore */
        $assetStore = Injector::inst()->get(AssetStore::class);
        $fileID = $assetStore->getFileID($asset->Filename, $asset->Hash, $asset->Variant);
        $filesystem = $assetStore->getProtectedFilesystem();
        if (!$forceProtected && !$filesystem->has($fileID)) {
            $filesystem = $assetStore->getPublicFilesystem();
        }
        /** @var LocalFilesystemAdapter $adapter */
        $adapter = $filesystem->getAdapter();
        return $relative ? $fileID : $adapter->prefixPath($fileID);
    }

    public function cleanFilename(string $filename)
    {
        $helper = Injector::inst()->get(HashFileIDHelper::class);
        return $helper->cleanFilename($filename);
    }

    public function getFileID($filename, $hash, $variant = null)
    {
        return parent::getFileID($filename, $hash, $variant);
    }

    public function parseFileID(string $fileID)
    {
        $parsedFileID = $this->getProtectedResolutionStrategy()->parseFileID($fileID);
        return $parsedFileID ? $parsedFileID->getTuple() : null;
    }

    public function getOriginalFilename(string $fileID)
    {
        $parsedFiledID = $this->getPublicResolutionStrategy()->parseFileID($fileID);
        return $parsedFiledID ? $parsedFiledID->getFilename() : null;
    }

    public function getFilesystemFor(string $fileID)
    {
        return $this->applyToFileIDOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs) {
                return $fs;
            },
            $fileID
        );
    }

    public function removeVariant(string $fileID)
    {
        $parsedFiledID = $this->getPublicResolutionStrategy()->parseFileID($fileID);
        if ($parsedFiledID) {
            return $this->getPublicResolutionStrategy()->buildFileID($parsedFiledID->setVariant(''));
        }

        return $fileID;
    }

    public function getDefaultConflictResolution($variant)
    {
        return parent::getDefaultConflictResolution($variant);
    }

    /**
     * Allows testing of grant status
     *
     * @param string $filename
     * @param string $hash
     */
    public function isGranted($fileID)
    {
        return parent::isGranted($fileID);
    }

    protected function isSeekableStream($stream)
    {
        if (isset(TestAssetStore::$seekable_override)) {
            // Unset the override so we don't get stuck in an infinite loop
            TestAssetStore::$seekable_override = null;
            return TestAssetStore::$seekable_override;
        }
        return parent::isSeekableStream($stream);
    }
}

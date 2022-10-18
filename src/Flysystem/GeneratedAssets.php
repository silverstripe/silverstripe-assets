<?php

namespace SilverStripe\Assets\Flysystem;

use Exception;
use SilverStripe\Assets\Flysystem\Filesystem;
use League\Flysystem\UnableToWriteFile;
use SilverStripe\Assets\Storage\GeneratedAssetHandler;

/**
 * Simple Flysystem implementation of GeneratedAssetHandler for storing generated content
 */
class GeneratedAssets implements GeneratedAssetHandler
{
    /**
     * Flysystem store for files
     *
     * @var Filesystem
     */
    protected $assetStore = null;

    /**
     * Assign the asset backend. This must be a filesystem
     * with an adapter of type {@see PublicAdapter}.
     *
     * @param Filesystem $store
     * @return $this
     */
    public function setFilesystem(Filesystem $store)
    {
        $this->assetStore = $store;
        return $this;
    }

    /**
     * Get the asset backend
     *
     * @return Filesystem
     * @throws Exception
     */
    public function getFilesystem()
    {
        if (!$this->assetStore) {
            throw new Exception("Filesystem misconfiguration error");
        }
        return $this->assetStore;
    }

    public function getContentURL($filename, $callback = null)
    {
        $result = $this->checkOrCreate($filename, $callback);
        if (!$result) {
            return null;
        }
        $filesystem = $this->getFilesystem();
        if (! $filesystem instanceof Filesystem) {
            return null;
        }
        $adapter = $filesystem->getAdapter();
        if (!$adapter instanceof PublicAdapter) {
            return null;
        }
        return $adapter->getPublicUrl($filename);
    }

    public function getContent($filename, $callback = null)
    {
        $result = $this->checkOrCreate($filename, $callback);
        if (!$result) {
            return null;
        }
        return $this
            ->getFilesystem()
            ->read($filename);
    }

    /**
     * Check if the file exists or that the $callback provided was able to regenerate it.
     *
     * @param string $filename
     * @param callable $callback
     * @return bool Whether or not the file exists
     * @throws UnableToWriteFile If an error has occurred during save
     */
    protected function checkOrCreate($filename, $callback = null)
    {
        // Check if there is an existing asset
        if ($this->getFilesystem()->has($filename)) {
            return true;
        }

        if (!$callback) {
            return false;
        }

        // Invoke regeneration and save
        $content = call_user_func($callback);
        $this->setContent($filename, $content);
        return true;
    }

    public function setContent($filename, $content)
    {
        // Store content
        $this->getFilesystem()->write($filename, $content);
    }

    public function removeContent($filename)
    {
        $filesystem = $this->getFilesystem();
        
        if ($filesystem->directoryExists($filename)) {
            $filesystem->deleteDirectory($filename);
        } elseif ($filesystem->fileExists($filename)) {
            $filesystem->delete($filename);
        }
    }
}

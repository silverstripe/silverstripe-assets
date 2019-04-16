<?php

namespace SilverStripe\Assets\Storage;

/**
 * Extension to the regular AssetStore interface with a few additional convenience methods meant to make it easy to
 * resolve variable path for FileIDs.
 *
 * This interface is not formalise yet. New methods may be added to it.
 * @expirmental
 */
interface ExtendedAssetStore extends AssetStore
{
    /**
     * Similar to publish, only any existing files that would be overriden by publishing will be moved back to the
     * protected store.
     * @param $filename
     * @param $hash
     * @return void
     */
    public function swapPublish($filename, $hash);

    /**
     * Try to resolve the provided path and move any matching file and its variant to their default location.
     * @param string $fileID
     * @return array List of moved files with the original file location as the key
     */
    public function normalisePath($fileID);

    /**
     *  Try to find the provided file and move it to its default location along with any matching variant.
     * @param string $filename
     * @param string $hash
     * @return array List of moved files with the original file location as the key
     */
    public function normalise($filename, $hash);
}

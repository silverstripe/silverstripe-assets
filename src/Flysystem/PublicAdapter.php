<?php

namespace SilverStripe\Assets\Flysystem;

use League\Flysystem\FilesystemAdapter;

/**
 * Represents an AbstractAdapter which exposes its assets via public urls
 */
interface PublicAdapter extends FilesystemAdapter
{

    /**
     * Provide downloadable url that is open to the public
     *
     * @param string $path
     * @return string|null
     */
    public function getPublicUrl($path);
}

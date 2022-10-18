<?php

namespace SilverStripe\Assets\Flysystem;

use League\Flysystem\FilesystemAdapter;

/**
 * An adapter which does not publicly expose protected files
 */
interface ProtectedAdapter extends FilesystemAdapter
{

    /**
     * Provide downloadable url that is restricted to granted users
     *
     * @param string $path
     * @return string|null
     */
    public function getProtectedUrl($path);
}

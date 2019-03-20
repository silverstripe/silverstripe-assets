<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Injector\Injectable;

/**
 * Parsed Hash path URLs. Hash path group a file and its variant under a directory based on an hash generated from the
 * content of the original file.
 *
 * Hash path are used by the Protected asset adapter and was the default for the public adapter prior to
 * SilverStripe 4.4.
 *
 * e.g.: `Uploads/a1312bc34d/sam__ResizedImageWzYwLDgwXQ.jpg`
 */
class HashFileIDHelper implements FileIDHelper
{
    use Injectable;

    /**
     * Default length at which hashes are truncated.
     */
    const HASH_TRUNCATE_LENGTH = 10;

    public function buildFileID($filename, $hash, $variant = null)
    {
        // Since we use double underscore to delimit variants, eradicate them from filename
        $filename = $this->cleanFilename($filename);
        $name = basename($filename);

        // Split extension
        $extension = null;
        if (($pos = strpos($name, '.')) !== false) {
            $extension = substr($name, $pos);
            $name = substr($name, 0, $pos);
        }

        $fileID = substr($hash, 0, self::HASH_TRUNCATE_LENGTH) . '/' . $name;

        // Add directory
        $dirname = ltrim(dirname($filename), '.');
        if ($dirname) {
            $fileID = $dirname . '/' . $fileID;
        }

        // Add variant
        if ($variant) {
            $fileID .= '__' . $variant;
        }

        // Add extension
        if ($extension) {
            $fileID .= $extension;
        }

        return $fileID;
    }

    public function cleanFilename($filename)
    {
        // Since we use double underscore to delimit variants, eradicate them from filename
        return preg_replace('/_{2,}/', '_', $filename);
    }

    public function parseFileID($fileID)
    {
        $pattern = '#^(?<folder>([^/]+/)*)(?<hash>[a-zA-Z0-9]{10})/(?<basename>((?<!__)[^/.])+)(__(?<variant>[^.]+))?(?<extension>(\..+)*)$#';

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern, $fileID, $matches)) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        return new ParsedFileID(
            $fileID,
            $filename,
            isset($matches['variant']) ? $matches['variant'] : '',
            isset($matches['hash']) ? $matches['hash'] : ''
        );
    }
}

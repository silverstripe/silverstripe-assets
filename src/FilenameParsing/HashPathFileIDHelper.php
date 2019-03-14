<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Injector\Injectable;

class HashPathFileIDHelper implements FileIDHelper
{
    use Injectable;

    const HASH_TRUNCATE_LENGTH = 10;

    /**
     * Map file tuple (hash, name, variant) to a filename to be used by flysystem
     *
     * @param string $filename Name of file
     * @param string $hash Hash of original file
     * @param string $variant (if given)
     * @return string Adapter specific identifier for this file/version
     */
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


    /**
     * Performs filename cleanup before sending it back.
     *
     * @param string $filename
     * @return string
     */
    public function cleanFilename($filename)
    {
        // Since we use double underscore to delimit variants, eradicate them from filename
        return preg_replace('/_{2,}/', '_', $filename);
    }

    /**
     * Get Filename, Variant and Hash from a file id
     *
     * @param string $fileID
     * @return ParsedFileID
     */
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
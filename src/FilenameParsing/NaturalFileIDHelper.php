<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Injector\Injectable;

/**
 * Parsed Natural path URLs. Natural path are the same hashless path that appear in the CMS.
 *
 * Natural paths are used by the public adapter from SilverStripe 4.4 and on the protected adapter when
 * `legacy_filenames` is enabled.
 *
 * e.g.: `Uploads/sam__ResizedImageWzYwLDgwXQ.jpg`
 */
class NaturalFileIDHelper implements FileIDHelper
{
    use Injectable;

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

        $fileID = $name;

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
        $pattern = '#^(?<folder>([^/]+/)*)(?<basename>((?<!__)[^/.])+)(__(?<variant>[^.]+))?(?<extension>(\..+)*)$#';

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern, $fileID, $matches)) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        return new ParsedFileID(
            $fileID,
            $filename,
            isset($matches['variant']) ? $matches['variant'] : ''
        );
    }
}

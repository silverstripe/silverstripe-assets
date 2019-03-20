<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Injector\Injectable;

/**
 * Parsed SS3 style legacy asset URLs. e.g.: `Uploads/_resampled/ResizedImageWzYwLDgwXQ/sam.jpg`
 *
 * SS3 legacy paths are no longer used in SilverStripe 4, but a way to parse them is needed for redirecting old SS3
 * urls.
 */
class LegacyPathFileIDHelper implements FileIDHelper
{
    use Injectable;

    public function buildFileID($filename, $hash, $variant = null)
    {
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

        // Add variant
        if ($variant) {
            $fileID = '_resampled/' . $variant . '/' . $fileID;
        }

        if ($dirname) {
            $fileID = $dirname . '/' . $fileID;
        }

        // Add extension
        if ($extension) {
            $fileID .= $extension;
        }

        return $fileID;
    }

    public function cleanFilename($filename)
    {
        // There's not really any relevant cleaning rule for legacy. It's not important any way because we won't
        // generating legacy URLs, aside from maybe for testing.
        return $filename;
    }

    /**
     * @note LegacyPathFileIDHelper is meant to fail when parsing newer format fileIDs with a variant e.g.:
     * `subfolder/abcdef7890/sam__resizeXYZ.jpg`. When parsing fileIDs without variant, it should return the same
     * results as natural paths.
     */
    public function parseFileID($fileID)
    {
        $pattern = '#^(?<folder>([^/]+/)*?)(_resampled/(?<variant>([^/.]+))/)?((?<basename>((?<!__)[^/.])+))(?<extension>(\..+)*)$#';

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
<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Injector\Injectable;

/**
 * Parsed Natural path URLs. Natural path is the same hashless path that appears in the CMS.
 *
 * Natural paths are used by the public adapter from SilverStripe 4.4 and on the protected adapter when
 * `legacy_filenames` is enabled.
 *
 * e.g.: `Uploads/sam__ResizedImageWzYwLDgwXQ.jpg`
 *
 * @internal This is still an evolving API. It may changed in the next minor release.
 */
class NaturalFileIDHelper implements FileIDHelper
{
    use Injectable;

    public function buildFileID($filename, $hash = null, $variant = null, $cleanfilename = true)
    {
        if ($filename instanceof ParsedFileID) {
            $hash =  $filename->getHash();
            $variant =  $filename->getVariant();
            $filename =  $filename->getFilename();
        }

        // Since we use double underscore to delimit variants, eradicate them from filename
        if ($cleanfilename) {
            $filename = $this->cleanFilename($filename);
        }
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
        if (!preg_match($pattern, $fileID, $matches) || strpos($matches['folder'], '_resampled') !== false) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        return new ParsedFileID(
            $filename,
            '',
            isset($matches['variant']) ? $matches['variant'] : '',
            $fileID
        );
    }

    public function isVariantOf($fileID, ParsedFileID $original)
    {
        $variant = $this->parseFileID($fileID);
        return $variant && $variant->getFilename() == $original->getFilename();
    }

    public function lookForVariantIn(ParsedFileID $parsedFileID)
    {
        $folder = dirname($parsedFileID->getFilename());
        return $folder == '.' ? '' : $folder;
    }
}

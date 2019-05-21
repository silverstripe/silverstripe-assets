<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Injector\Injectable;

/**
 * Parsed SS3 style legacy asset URLs. e.g.: `Uploads/_resampled/ResizedImageWzYwLDgwXQ/sam.jpg`
 *
 * SS3 legacy paths are no longer used in SilverStripe 4, but a way to parse them is needed for redirecting old SS3
 * urls.
 *
 * @internal This is still an evolving API. It may change in the next minor release.
 */
class LegacyFileIDHelper implements FileIDHelper
{
    use Injectable;

    /** @var bool */
    private $failNewerVariant;

    /**
     * @param bool $failNewerVariant Whether FileID mapping to newer SS4 formats should be parsed.
     */
    public function __construct($failNewerVariant = true)
    {
        $this->failNewerVariant = $failNewerVariant;
    }


    public function buildFileID($filename, $hash = null, $variant = null, $cleanfilename = true)
    {
        if ($filename instanceof ParsedFileID) {
            $variant =  $filename->getVariant();
            $filename =  $filename->getFilename();
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

        // Add variant
        if ($variant) {
            $fileID = '_resampled/' . str_replace('_', '/', $variant) . '/' . $fileID;
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
        // There's not really any relevant cleaning rule for legacy. It's not important any way because we won't be
        // generating legacy URLs, aside from maybe for testing.
        return $filename;
    }

    /**
     * @note LegacyFileIDHelper is meant to fail when parsing newer format fileIDs with a variant e.g.:
     * `subfolder/abcdef7890/sam__resizeXYZ.jpg`. When parsing fileIDs without a variant, it should return the same
     * results as natural paths. This behavior can be disabled by setting `failNewerVariant` to false on the
     * constructor.
     */
    public function parseFileID($fileID)
    {
        if ($this->failNewerVariant) {
            $pattern = '#^(?<folder>([^/]+/)*?)(_resampled/(?<variant>([^.]+))/)?((?<basename>((?<!__)[^/.])+))(?<extension>(\..+)*)$#';
        } else {
            $pattern = '#^(?<folder>([^/]+/)*?)(_resampled/(?<variant>([^.]+))/)?((?<basename>([^/.])+))(?<extension>(\..+)*)$#';
        }


        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern, $fileID, $matches)) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        return new ParsedFileID(
            $filename,
            '',
            isset($matches['variant']) ? str_replace('/', '_', $matches['variant']) : '',
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
        if ($folder == '.') {
            $folder = '';
        } else {
            $folder .= '/';
        }
        return $folder . '_resampled';
    }
}

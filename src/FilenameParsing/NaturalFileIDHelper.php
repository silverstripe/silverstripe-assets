<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Injector\Injectable;

/**
 * Parsed Natural path URLs. Natural path is the same hashless path that appears in the CMS.
 *
 * Natural paths are used by the public adapter from SilverStripe 4.4
 *
 * e.g.: `Uploads/sam__ResizedImageWzYwLDgwXQ.jpg`
 */
class NaturalFileIDHelper extends AbstractFileIDHelper
{
    public function parseFileID($fileID)
    {
        $pattern = '#^(?<folder>([^/]+/)*)(?<basename>((?<!__)[^/.])+)(__(?<variant>[^.]+))?(?<extension>(\..+)*)$#';

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern ?? '', $fileID ?? '', $matches) || strpos($matches['folder'] ?? '', '_resampled') !== false) {
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
        $folder = dirname($parsedFileID->getFilename() ?? '');
        return $folder == '.' ? '' : $folder;
    }

    protected function getFileIDBase($shortFilename, $fullFilename, $hash, $variant): string
    {
        return $shortFilename;
    }

    protected function validateFileParts($filename, $hash, $variant): void
    {
        // no-op
    }
}

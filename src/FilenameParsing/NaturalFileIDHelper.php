<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Assets\File;

/**
 * Parsed Natural path URLs. Natural path is the same hashless path that appears in the CMS.
 *
 * Natural paths are used by the public adapter from SilverStripe 4.4
 *
 * e.g.: `Uploads/sam__ResizedImageWzYwLDgwXQ.jpg`
 */
class NaturalFileIDHelper extends AbstractFileIDHelper implements GlobbableFileIDHelper
{
    public function parseFileID($fileID)
    {
        $pattern = '#^(?<folder>([^/]+/)*)(?<basename>((?<!'
            . FileIDHelper::VARIANT_SEPARATOR . ')[^/.])+)('
            . FileIDHelper::VARIANT_SEPARATOR . '(?<variant>[^.]+))?(?<extension>(\..+)*)$#';

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern ?? '', $fileID ?? '', $matches) || strpos($matches['folder'] ?? '', '_resampled') !== false) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        $variant = $matches['variant'] ?: '';

        if ($variant) {
            $filename = $this->swapExtension($filename, $variant, NaturalFileIDHelper::EXTENSION_ORIGINAL);
        }

        return new ParsedFileID(
            $filename,
            '',
            $variant,
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

    public function getVariantGlob(string $folder, ParsedFileID $parsedFileID): string
    {
        $folderRegex = '#^' . preg_quote($folder, '#') . '/#';
        $globFilePath = preg_replace($folderRegex, '', $parsedFileID->getFilename());
        $extRegex = '#\.' . preg_quote(File::get_file_extension($globFilePath), '#') . '$#';
        $globFilePathWithoutExtension = preg_replace($extRegex, '', $globFilePath);
        return $globFilePathWithoutExtension . FileIDHelper::VARIANT_SEPARATOR . '*';
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

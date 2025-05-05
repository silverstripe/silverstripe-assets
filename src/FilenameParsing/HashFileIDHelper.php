<?php

namespace SilverStripe\Assets\FilenameParsing;

use InvalidArgumentException;
use SilverStripe\Assets\File;

/**
 * Parsed Hash path URLs. Hash paths group a file and its variant under a directory based on a hash generated from the
 * content of the original file.
 *
 * Hash paths are used by the Protected asset adapter and was the default for the public adapter prior to
 * SilverStripe 4.4.
 *
 * e.g.: `Uploads/a1312bc34d/sam__ResizedImageWzYwLDgwXQ.jpg`
 */
class HashFileIDHelper extends AbstractFileIDHelper implements GlobbableFileIDHelper
{
    /**
     * Default length at which hashes are truncated.
     */
    const HASH_TRUNCATE_LENGTH = 10;

    public function parseFileID($fileID)
    {
        $pattern = '#^(?<folder>([^/]+/)*)(?<hash>[a-f0-9]{10})/(?<basename>((?<!'
            . FileIDHelper::VARIANT_SEPARATOR . ')[^/.])+)('
            . FileIDHelper::VARIANT_SEPARATOR . '(?<variant>[^.]+))?(?<extension>(\..+)*)$#';

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern ?? '', $fileID ?? '', $matches)) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        $variant = $matches['variant'] ?: '';

        if ($variant) {
            $filename = $this->swapExtension($filename, $variant, HashFileIDHelper::EXTENSION_ORIGINAL);
        }

        return new ParsedFileID(
            $filename,
            $matches['hash'],
            $variant,
            $fileID
        );
    }

    public function isVariantOf($fileID, ParsedFileID $original)
    {
        $variant = $this->parseFileID($fileID);
        return $variant &&
            $variant->getFilename() == $original->getFilename() &&
            $variant->getHash() == $this->truncate($original->getHash());
    }

    public function lookForVariantIn(ParsedFileID $parsedFileID)
    {
        $folder = dirname($parsedFileID->getFilename() ?? '');
        if ($folder == '.') {
            $folder = '';
        } else {
            $folder .= '/';
        }
        return  $folder . $this->truncate($parsedFileID->getHash());
    }

    public function getVariantGlob(string $folder, ParsedFileID $parsedFileID): string
    {
        $truncatedHash = $this->truncate($parsedFileID->getHash());
        $folderWithoutHash = str_replace('/' . $truncatedHash, '', $folder);
        $folderRegex = '#^' . preg_quote($folderWithoutHash, '#') . '/#';
        $globFilePath = preg_replace($folderRegex, '', $parsedFileID->getFilename());
        $extRegex = '#\.' . preg_quote(File::get_file_extension($globFilePath), '#') . '$#';
        $globFilePathWithoutExtension = preg_replace($extRegex, '', $globFilePath);
        return $globFilePathWithoutExtension . FileIDHelper::VARIANT_SEPARATOR . '*';
    }

    protected function validateFileParts($filename, $hash, $variant): void
    {
        if (empty($hash)) {
            throw new InvalidArgumentException('HashFileIDHelper::buildFileID requires an $hash value.');
        }
    }

    protected function getFileIDBase($shortFilename, $fullFilename, $hash, $variant): string
    {
        return $this->truncate($hash) . '/' . $shortFilename;
    }

    /**
     * Truncate a hash to a predefined length
     * @param $hash
     * @return string
     */
    private function truncate($hash)
    {
        return substr($hash ?? '', 0, HashFileIDHelper::HASH_TRUNCATE_LENGTH);
    }
}

<?php

namespace SilverStripe\Assets\FilenameParsing;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;

/**
 * Parsed Hash path URLs. Hash paths group a file and its variant under a directory based on a hash generated from the
 * content of the original file.
 *
 * Hash paths are used by the Protected asset adapter and was the default for the public adapter prior to
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

    public function buildFileID($filename, $hash = null, $variant = null, $cleanfilename = true)
    {
        if ($filename instanceof ParsedFileID) {
            $hash =  $filename->getHash();
            $variant =  $filename->getVariant();
            $filename =  $filename->getFilename();
        }

        if (empty($hash)) {
            throw new InvalidArgumentException('HashFileIDHelper::buildFileID requires an $hash value.');
        }

        // Since we use double underscore to delimit variants, eradicate them from filename
        if ($cleanfilename) {
            $filename = $this->cleanFilename($filename);
        }
        $name = basename($filename ?? '');

        // Split extension
        $extension = null;
        if (($pos = strpos($name ?? '', '.')) !== false) {
            $extension = substr($name ?? '', $pos ?? 0);
            $name = substr($name ?? '', 0, $pos);
        }

        $fileID = $this->truncate($hash) . '/' . $name;

        // Add directory
        $dirname = ltrim(dirname($filename ?? ''), '.');
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
        // Swap backslash for forward slash
        $filename = str_replace('\\', '/', $filename ?? '');

        // Since we use double underscore to delimit variants, eradicate them from filename
        return preg_replace('/_{2,}/', '_', $filename ?? '');
    }

    public function parseFileID($fileID)
    {
        $pattern = '#^(?<folder>([^/]+/)*)(?<hash>[a-f0-9]{10})/(?<basename>((?<!__)[^/.])+)(__(?<variant>[^.]+))?(?<extension>(\..+)*)$#';

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern ?? '', $fileID ?? '', $matches)) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        return new ParsedFileID(
            $filename,
            $matches['hash'],
            isset($matches['variant']) ? $matches['variant'] : '',
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

    /**
     * Truncate a hash to a predefined length
     * @param $hash
     * @return string
     */
    private function truncate($hash)
    {
        return substr($hash ?? '', 0, self::HASH_TRUNCATE_LENGTH);
    }

    public function lookForVariantRecursive(): bool
    {
        return false;
    }
}

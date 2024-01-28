<?php

namespace SilverStripe\Assets\FilenameParsing;

/**
 * Helps build and parse Filename Identifiers (ake: FileIDs) according to a predefined format.
 */
interface FileIDHelper
{
    /**
     * Map file tuple (hash, name, variant) to a filename to be used by flysystem
     *
     * @param string|ParsedFileID $filename Name of file or ParsedFileID object
     * @param string $hash Hash of original file
     * @param string $variant (if given)
     * @param bool $cleanfilename Whether the filename should be cleaned before building the file ID. Defaults to true.
     * @return string Adapter specific identifier for this file/version
     */
    public function buildFileID($filename, $hash = null, $variant = null, $cleanfilename = true);


    /**
     * Clean up filename to remove constructs that might clash with the underlying path format of this FileIDHelper.
     *
     * @param string $filename
     * @return string
     */
    public function cleanFilename($filename);

    /**
     * Get Filename, Variant and Hash from a fileID. If a FileID can not be parsed, returns `null`.
     *
     * @param string $fileID
     * @return ParsedFileID|null
     */
    public function parseFileID($fileID);

    /**
     * Determine if the provided fileID is a variant of `$parsedFileID`.
     * @param string $fileID
     * @param ParsedFileID $parsedFileID
     * @return boolean
     */
    public function isVariantOf($fileID, ParsedFileID $parsedFileID);

    /**
     * Compute the relative path where variants of the provided parsed file ID are expected to be stored.
     *
     * @param ParsedFileID $parsedFileID
     * @return string
     */
    public function lookForVariantIn(ParsedFileID $parsedFileID);

    /**
     * Specify if this File ID Helper stores variants in subfolders and require a recursive look up to find all
     * variants.
     * @return bool
     */
    public function lookForVariantRecursive(): bool;
}

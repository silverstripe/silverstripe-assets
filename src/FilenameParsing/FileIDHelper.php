<?php

namespace SilverStripe\Assets\FilenameParsing;

/**
 * Helps build and parse FileIDs according to a predefined format.
 */
interface FileIDHelper
{

    /**
     * Map file tuple (hash, name, variant) to a filename to be used by flysystem
     *
     * @param string $filename Name of file
     * @param string $hash Hash of original file
     * @param string $variant (if given)
     * @return string Adapter specific identifier for this file/version
     */
    public function buildFileID($filename, $hash, $variant = null);


    /**
     * Performs filename cleanup before sending it back.
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
}

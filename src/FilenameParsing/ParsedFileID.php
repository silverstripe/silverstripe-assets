<?php

namespace SilverStripe\Assets\FilenameParsing;

/**
 * Parsed fileID broken down into its sub-components.
 */
class ParsedFileID
{

    /** @var string */
    private $fileID;

    /** @var string */
    private $filename;

    /** @var string */
    private $variant;

    /** @var string */
    private $hash;

    /**
     * ParsedFileID constructor.
     * @param string $fileID
     * @param string $filename
     * @param string $variant
     * @param string $hash
     */
    public function __construct($fileID, $filename, $variant='', $hash='')
    {
        $this->fileID = $fileID;
        $this->filename = $filename;
        $this->variant = $variant ?: '';
        $this->hash = $hash ?: '';
    }

    /**
     * The Original File ID that was parsed.
     * @return string
     */
    public function getOriginalFileID()
    {
        return $this->fileID;
    }

    /**
     * Filename component.
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * Variant component. Usually a string representing some resized version of an image.
     * @return string
     */
    public function getVariant()
    {
        return $this->variant;
    }

    /**
     * Hash build from the content of the file. Usually the first 10 characters of sha1 hash.
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }

    /**
     * Convert this parsed file ID to an array representation.
     * @return array
     */
    public function getTuple()
    {
        return [
            'Filename'  => $this->filename,
            'Variant'   => $this->variant ?: null,
            'Hash'      => $this->hash ?: null
        ];
    }



}
<?php

namespace SilverStripe\Assets\FilenameParsing;

/**
 * Immutable representation of a parsed fileID broken down into its sub-components.
 *
 * @internal This is still an evolving API. It may change in the next minor release.
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
     * @param string $filename
     * @param string $hash
     * @param string $variant
     * @param string $fileID Original FileID use to generate this ParsedFileID
     */
    public function __construct($filename, $hash = '', $variant = '', $fileID = '')
    {
        $this->filename = $filename;
        $this->hash = $hash ?: '';
        $this->variant = $variant ?: '';
        $this->fileID = $fileID ?: '';
    }

    /**
     * The File ID associated with this ParsedFileID if known, or blank if unknown.
     * @return string
     */
    public function getFileID()
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
            'Variant'   => $this->variant ?: '',
            'Hash'      => $this->hash ?: ''
        ];
    }

    /**
     * @param string $fileID
     * @return self
     */
    public function setFileID($fileID)
    {
        return new self($this->filename, $this->hash, $this->variant, $fileID);
    }

    /**
     * @param string $filename
     * @return self
     */
    public function setFilename($filename)
    {
        return new self($filename, $this->hash, $this->variant, $this->fileID);
    }

    /**
     * @param string $variant
     * @return self
     */
    public function setVariant($variant)
    {
        return new self($this->filename, $this->hash, $variant, $this->fileID);
    }

    /**
     * @param string $hash
     * @return self
     */
    public function setHash($hash)
    {
        return new self($this->filename, $hash, $this->variant, $this->fileID);
    }
}

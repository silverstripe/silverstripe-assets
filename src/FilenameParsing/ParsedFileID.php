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
     * @return string
     */
    public function getOriginalFileID()
    {
        return $this->fileID;
    }

    /**
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * @return string
     */
    public function getVariant()
    {
        return $this->variant;
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return $this->hash;
    }


    public function getTuple()
    {
        $tuple = [
            'Filename' => $this->filename
        ];

        if ($this->variant) {
            $tuple['Variant'] = $this->variant;
        }

        if ($this->hash) {
            $tuple['Hash'] = $this->hash;
        }

        return $tuple;
    }



}
<?php
namespace SilverStripe\Assets\FilenameParsing;

use League\Flysystem\Filesystem;

/**
 * Represents a strategy for resolving files on a Flysystem Adapter.
 */
interface FileResolutionStrategy
{
    /**
     * Try to resolve a file ID against the provided Filesystem.
     * @param string $fileID
     * @param Filesystem $filesystem
     * @return ParsedFileID|null Alternative FileID where the user should be redirected to.
     */
    public function resolveFileID($fileID, Filesystem $filesystem);

    /**
     * Try to resolve a file ID against the provided Filesystem looking at newer version of the file.
     * @param string $fileID
     * @param Filesystem $filesystem
     * @return ParsedFileID|null Alternative FileID where the user should be redirected to.
     */
    public function softResolveFileID($fileID, Filesystem $filesystem);

    /**
     * Build a file ID for a variant so it follows the pattern of it's original file. The variant may not exists on the
     * Filesystem yet, but the original file has to. This is to make sure that variant file alway follow the same
     * pattern as the original file they are attached to.
     * @param ParsedFileID|array $tuple
     * @param Filesystem $filesystem
     * @return ParsedFileID
     */
    public function generateVariantFileID($tuple, Filesystem $filesystem);

    /**
     * Try to find a file ID for an existing file the provided file tuple.
     * @param array|ParsedFileID $tuple
     * @param Filesystem $filesystem
     * @param boolean $strict Whatever we should enforce a hash check on the file we find
     * @return ParsedFileID|null FileID
     */
    public function searchForTuple($tuple, Filesystem $filesystem, $strict = true);


    /**
     * Build a file ID for the provided tuple, irrespective of its existence.
     *
     * Should always return the prefered file ID for this resolution strategy.
     *
     * @param array|ParsedFileID $tuple
     * @return string
     */
    public function buildFileID($tuple);

    /**
     * Try to resolve the provided file ID string irrespective of whatever it exists on the Filesystem or not.
     * @param $fileID
     * @return ParsedFileID
     */
    public function parseFileID($fileID);

    /**
     * Find all the variants of the provided tuple
     * @param array|ParsedFileID $tuple
     * @param Filesystem $filesystem
     * @return generator|ParsedFileID[]|null
     */
    public function findVariants($tuple, Filesystem $filesystem);

    /**
     * Normalise a filename to be consistent with this file reoslution startegy.
     * @param string $filename
     * @return string
     */
    public function cleanFilename($filename);

    /**
     * Given a fileID string or a Parsed File ID, create a matching ParsedFileID without any variant.
     * @param string|ParsedFileID $fileID
     * @return ParsedFileID|null A ParsedFileID with a the expected FileID of the original file or null if the provided
     * $fileID could not be understood
     */
    public function stripVariant($fileID);
}

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
     * Try to resolve a file ID against the provided Filesystem looking at newer versions of the file.
     * @param string $fileID
     * @param Filesystem $filesystem
     * @return ParsedFileID|null Alternative FileID where the user should be redirected to.
     */
    public function softResolveFileID($fileID, Filesystem $filesystem);

    /**
     * Build a file ID for a variant so it follows the pattern of its original file. The variant may not exist on the
     * Filesystem yet, but the original file has to. This is to make sure that variant files always follow the same
     * pattern as the original file they are attached to.
     * @param ParsedFileID|array $tuple
     * @param Filesystem $filesystem
     * @return ParsedFileID
     */
    public function generateVariantFileID($tuple, Filesystem $filesystem);

    /**
     * Try to find a file ID for an existing file using the provided file tuple.
     * @param array|ParsedFileID $tuple
     * @param Filesystem $filesystem
     * @param boolean $strict Whether we should enforce a hash check on the file we find
     * @return ParsedFileID|null FileID
     */
    public function searchForTuple($tuple, Filesystem $filesystem, $strict = true);


    /**
     * Build a file ID for the provided tuple, irrespective of its existence.
     *
     * Should always return the preferred file ID for this resolution strategy.
     *
     * @param array|ParsedFileID $tuple
     * @return string
     */
    public function buildFileID($tuple);

    /**
     * Try to resolve the provided file ID string irrespective of whether it exists on the Filesystem or not.
     * @param $fileID
     * @return ParsedFileID
     */
    public function parseFileID($fileID);

    /**
     * Find all the variants of the provided tuple
     * @param array|ParsedFileID $tuple
     * @param Filesystem $filesystem
     * @return generator|ParsedFileID[]|null
     * @throws \League\Flysystem\UnableToCheckExistence
     */
    public function findVariants($tuple, Filesystem $filesystem);

    /**
     * Normalise a filename to be consistent with this file resolution strategy.
     * @param string $filename
     * @return string
     */
    public function cleanFilename($filename);

    /**
     * Given a fileID string or a Parsed File ID, create a matching ParsedFileID without any variant.
     * @param string|ParsedFileID $fileID
     * @return ParsedFileID|null A ParsedFileID with the expected FileID of the original file or null if the provided
     * $fileID could not be understood
     */
    public function stripVariant($fileID);
}

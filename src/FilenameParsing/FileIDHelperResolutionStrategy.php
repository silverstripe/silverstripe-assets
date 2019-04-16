<?php

namespace SilverStripe\Assets\FilenameParsing;

use InvalidArgumentException;
use League\Flysystem\Filesystem;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Assets\File;
use SilverStripe\ORM\DB;

/**
 * File resolution strategy that relies on a list of FileIDHelpers to find files.
 * * `DefaultFileIDHelper` is the default helper use to generate new file ID.
 * * `ResolutionFileIDHelpers` can contain a list of helpers that will be used to try to find existing file.
 *
 * This file resolution strategy can be helpfull when the approach to resolving files has changed over time and you need
 * older file format to resolve.
 *
 * You may also provide a `VersionedStage` to only look at files that were published.
 *
 * @internal This is still an evolving API. It may change in the next minor release.
 */
class FileIDHelperResolutionStrategy implements FileResolutionStrategy
{
    /**
     * The FileID helper that will be use to build FileID for this adapter.
     * @var FileIDHelper
     */
    private $defaultFileIDHelper;

    /**
     * List of FileIDHelper that should be use to try to parse FileIDs on this adapter.
     * @var FileIDHelper[]
     */
    private $resolutionFileIDHelpers;

    /**
     * Constrain this strategy to the a specific versioned stage.
     * @var string
     */
    private $versionedStage = Versioned::DRAFT;

    public function resolveFileID($fileID, Filesystem $filesystem)
    {
        $parsedFileID = $this->parseFileID($fileID);

        if ($parsedFileID) {
            return $this->searchForTuple($parsedFileID, $filesystem, false);
        }

        // If we couldn't resolve the file ID, we bail
        return null;
    }

    public function softResolveFileID($fileID, Filesystem $filesystem)
    {
        // If File is not versionable, let's bail
        if (!class_exists(Versioned::class) || !File::has_extension(Versioned::class)) {
            return null;
        }

        $parsedFileID = $this->parseFileID($fileID);

        if (!$parsedFileID) {
            return null;
        }

        $hash = $parsedFileID->getHash();
        $tuple = $hash ? $this->resolveWithHash($parsedFileID) : $this->resolveHashless($parsedFileID);

        if ($tuple) {
            return $this->searchForTuple($tuple, $filesystem, false);
        }

        // If we couldn't resolve the file ID, we bail
        return null;
    }

    /**
     * Try to find a DB reference for this parsed file ID. Return a file tuple if a equivalent file is found.
     * @param ParsedFileID $parsedFileID
     * @return ParsedFileID|null
     */
    private function resolveWithHash(ParsedFileID $parsedFileID)
    {
        // Try to find a version for a given stage
        /** @var File $file */
        $file = Versioned::withVersionedMode(function () use ($parsedFileID) {
            Versioned::set_stage($this->getVersionedStage());
            return File::get()->filter(['FileFilename' => $parsedFileID->getFilename()])->first();
        });

        // Could not find a valid file, let's bail.
        if (!$file) {
            return null;
        }

        $dbHash = $file->getHash();
        if (strpos($dbHash, $parsedFileID->getHash()) === 0) {
            return $parsedFileID;
        }

        // If we found a matching live file, let's see if our hash was publish at any point

        // Build a version filter
        $versionFilters = [
            ['"FileHash" like ?' => DB::get_conn()->escapeString($parsedFileID->getHash()) . '%'],
            ['not "FileHash" like ?' => DB::get_conn()->escapeString($file->getHash())],
        ];
        if ($this->getVersionedStage() == Versioned::LIVE) {
            // If we a limited to the Live stage, let's only look at files that have bee published
            $versionFilters['"WasPublished"'] = true;
        }

        $oldVersionCount = $file->allVersions($versionFilters, "", 1)->count();
        // Our hash was published at some other stage
        if ($oldVersionCount > 0) {
            return new ParsedFileID($file->getFilename(), $file->getHash(), $parsedFileID->getVariant());
        }

        return null;
    }

    /**
     * Try to find a DB reference for this parsed file ID that doesn't have an hash. Return a file tuple if a
     * equivalent file is found.
     * @param ParsedFileID $parsedFileID
     * @return array|null
     */
    private function resolveHashless(ParsedFileID $parsedFileID)
    {
        $filename = $parsedFileID->getFilename();
        $variant = $parsedFileID->getVariant();

        // Let's try to match the plain file name
        /** @var File $file */
        $file = Versioned::withVersionedMode(function () use ($filename) {
            Versioned::set_stage($this->getVersionedStage());
            return File::get()->filter(['FileFilename' => $filename])->first();
        });

        if ($file) {
            return [
                'Filename' => $filename,
                'Hash' => $file->getHash(),
                'Variant' => $variant
            ];
        }

        return null;
    }

    public function generateVariantFileID($tuple, Filesystem $fs)
    {
        $parsedFileID = $this->preProcessTuple($tuple);
        if (empty($parsedFileID->getVariant())) {
            return $this->searchForTuple($parsedFileID, $fs);
        }

        // Let's try to find a helper who can understand our file ID
        foreach ($this->resolutionFileIDHelpers as $helper) {
            if ($this->validateHash($helper, $parsedFileID, $fs)) {
                return $parsedFileID->setFileID(
                    $helper->buildFileID(
                        $parsedFileID->getFilename(),
                        $parsedFileID->getHash(),
                        $parsedFileID->getVariant()
                    )
                );
            }
        }

        return null;
    }

    public function searchForTuple($tuple, Filesystem $filesystem, $strict = true)
    {
        $parsedFileID = $this->preProcessTuple($tuple);
        $helpers = $this->getResolutionFileIDHelpers();
        array_unshift($helpers, $this->getDefaultFileIDHelper());

        $enforceHash = $strict && $parsedFileID->getHash();

        // When trying to resolve a file ID it's possible that we don't know it's hash.
        // We'll try our best to get it from the DB
        if (empty($parsedFileID->getHash())) {
            $filename = $parsedFileID->getFilename();
            if (class_exists(Versioned::class) && File::has_extension(Versioned::class)) {
                   $hashList = Versioned::withVersionedMode(function () use ($filename) {
                       Versioned::set_stage($this->getVersionedStage());
                       $vals = File::get()->map('ID', 'FileFilename')->toArray();
                       return File::get()
                           ->filter(['FileFilename' => $filename, 'FileVariant' => null])
                           ->limit(1)
                           ->column('FileHash');
                   });
            } else {
                $hashList = File::get()
                   ->filter(['FileFilename' => $filename])
                   ->limit(1)
                   ->column('FileHash');
            }

            // In theory, we could get more than one file with the same Filename. We wouldn't know how to tell
            // them apart any way so we'll just look at the first hash
            if (!empty($hashList)) {
                $parsedFileID = $parsedFileID->setHash($hashList[0]);
            }
        }

        foreach ($helpers as $helper) {
            try {
                $fileID = $helper->buildFileID(
                    $parsedFileID->getFilename(),
                    $parsedFileID->getHash(),
                    $parsedFileID->getVariant()
                );
            } catch (InvalidArgumentException $ex) {
                // Some file ID helper will throw an exception if you ask them to build a file ID wihtout an hash
                continue;
            }

            if ($filesystem->has($fileID)) {
                if ($enforceHash && !$this->validateHash($helper, $parsedFileID, $filesystem)) {
                    // We found a file, but its hash doesn't match the hash of our tuple.
                     continue;
                }
                if (empty($parsedFileID->getHash()) &&
                    $fullHash = $this->findHashOf($helper, $parsedFileID, $filesystem)
                ) {
                    $parsedFileID = $parsedFileID->setHash($fullHash);
                }
                return $parsedFileID->setFileID($fileID);
            }
        }
        return null;
    }

    /**
     * Try to validate the hash of a physical file against the expected hash from the parsed file ID.
     * @param FileIDHelper $helper
     * @param ParsedFileID $parsedFileID
     * @param Filesystem $filesystem
     * @return bool
     */
    private function validateHash(FileIDHelper $helper, ParsedFileID $parsedFileID, Filesystem $filesystem)
    {
        // We assumme that hashless parsed file ID are always valid
        if (!$parsedFileID->getHash()) {
            return true;
        }

        // Check if the physical hash of the file starts with our parsed file ID hash
        $actualHash = $this->findHashOf($helper, $parsedFileID, $filesystem);
        return strpos($actualHash, $parsedFileID->getHash()) === 0;
    }

    /**
     * Get the full hash for the provided Parsed File ID,
     * @param FileIDHelper $helper
     * @param ParsedFileID $parsedFileID
     * @param Filesystem $filesystem
     * @return bool|string
     */
    private function findHashOf(FileIDHelper $helper, ParsedFileID $parsedFileID, Filesystem $filesystem)
    {
        // Re build the file ID but without the variant
        $fileID = $helper->buildFileID(
            $parsedFileID->getFilename(),
            $parsedFileID->getHash()
        );

        // Couldn't find the original file, let's bail.
        if (!$filesystem->has($fileID)) {
            return false;
        }

        // Get hash from stream
        $stream = $filesystem->readStream($fileID);
        $hc = hash_init('sha1');
        hash_update_stream($hc, $stream);
        $fullHash = hash_final($hc);

        return $fullHash;
    }

    /**
     * Receive a tuple under various formats and normalise it back to a ParsedFileID object.
     * @param $tuple
     * @return ParsedFileID
     * @throws \InvalidArgumentException
     */
    private function preProcessTuple($tuple)
    {
        // Pre-format our tuple
        if ($tuple instanceof ParsedFileID) {
            return $tuple;
        } elseif (!is_array($tuple)) {
            throw new \InvalidArgumentException(
                'AssetAdapter expect $tuples to be an array or a ParsedFileID'
            );
        }

        return new ParsedFileID($tuple['Filename'], $tuple['Hash'], $tuple['Variant']);
    }

    /**
     * @return FileIDHelper
     */
    public function getDefaultFileIDHelper()
    {
        return $this->defaultFileIDHelper;
    }

    /**
     * @param FileIDHelper $defaultFileIDHelper
     */
    public function setDefaultFileIDHelper($defaultFileIDHelper)
    {
        $this->defaultFileIDHelper = $defaultFileIDHelper;
    }

    /**
     * @return FileIDHelper[]
     */
    public function getResolutionFileIDHelpers()
    {
        return $this->resolutionFileIDHelpers;
    }

    /**
     * @param FileIDHelper[] $resolutionFileIDHelpers
     */
    public function setResolutionFileIDHelpers(array $resolutionFileIDHelpers)
    {
        $this->resolutionFileIDHelpers = $resolutionFileIDHelpers;
    }

    /**
     * @return string
     */
    public function getVersionedStage()
    {
        return $this->versionedStage;
    }

    /**
     * @param string $versionedStage
     */
    public function setVersionedStage($versionedStage)
    {
        $this->versionedStage = $versionedStage;
    }


    public function buildFileID($tuple)
    {
        $parsedFileID = $this->preProcessTuple($tuple);
        return $this->getDefaultFileIDHelper()->buildFileID(
            $parsedFileID->getFilename(),
            $parsedFileID->getHash(),
            $parsedFileID->getVariant()
        );
    }

    public function findVariants($tuple, Filesystem $filesystem)
    {
        $parsedFileID = $this->preProcessTuple($tuple);

        $helpers = $this->getResolutionFileIDHelpers();
        array_unshift($helpers, $this->getDefaultFileIDHelper());

        // Search for a helper that will allow us to find a file
        /** @var FileIDHelper $helper */
        $helper = null;
        foreach ($helpers as $helper) {
            $fileID = $helper->buildFileID(
                $parsedFileID->getFilename(),
                $parsedFileID->getHash()
            );

            if (!$filesystem->has($fileID) || !$this->validateHash($helper, $parsedFileID, $filesystem)) {
                // This helper didn't find our file
                continue;
            }

            $folder = $helper->lookForVariantIn($parsedFileID);
            $possibleVariants = $filesystem->listContents($folder, true);
            foreach ($possibleVariants as $possibleVariant) {
                if ($possibleVariant['type'] !== 'dir' && $helper->isVariantOf($possibleVariant['path'], $parsedFileID)) {
                    yield $helper->parseFileID($possibleVariant['path'])->setHash($parsedFileID->getHash());
                }
            }
        }
    }
    
    public function cleanFilename($filename)
    {
        return $this->getDefaultFileIDHelper()->cleanFilename($filename);
    }
    
    public function parseFileID($fileID)
    {
        foreach ($this->resolutionFileIDHelpers as $fileIDHelper) {
            $parsedFileID = $fileIDHelper->parseFileID($fileID);
            if ($parsedFileID) {
                return $parsedFileID;
            }
        }

        return null;
    }

    public function stripVariant($fileID)
    {
        $hash = '';

        // File ID can be a string or a ParsedFileID
        // Normalise our parameters
        if ($fileID instanceof ParsedFileID) {
            // Let's get data out of our parsed file ID
            $parsedFileID = $fileID;
            $fileID = $parsedFileID->getFileID();
            $hash = $parsedFileID->getHash();

            // Our Parsed File ID has a blank FileID attached to it. This means we are dealing with a file that hasn't
            // been create yet. Let's used our default file ID helper
            if (empty($fileID)) {
                return $this->stripVariantFromParsedFileID($parsedFileID, $this->getDefaultFileIDHelper());
            }
        }

        // We don't know what helper was use to build this file ID
        // Let's try to find a helper who can understand our file ID
        foreach ($this->resolutionFileIDHelpers as $fileIDHelper) {
            $parsedFileID = $fileIDHelper->parseFileID($fileID);
            if ($parsedFileID) {
                if ($hash) {
                    $parsedFileID = $parsedFileID->setHash($hash);
                }
                return $this->stripVariantFromParsedFileID($parsedFileID, $fileIDHelper);
            }
        }

        return null;
    }

    /**
     * @param ParsedFileID $parsedFileID
     * @param FileIDHelper $helper
     * @return ParsedFileID
     */
    private function stripVariantFromParsedFileID(ParsedFileID $parsedFileID, FileIDHelper $helper)
    {
        $parsedFileID = $parsedFileID->setVariant('');

        try {
            return $parsedFileID->setFileID($helper->buildFileID($parsedFileID));
        } catch (InvalidArgumentException $ex) {
            return null;
        }
    }
}

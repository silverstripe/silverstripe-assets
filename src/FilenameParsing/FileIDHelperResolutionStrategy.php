<?php

namespace SilverStripe\Assets\FilenameParsing;

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
        foreach ($this->resolutionFileIDHelpers as $fileIDHelper) {
            $parsedFileID = $fileIDHelper->parseFileID($fileID);
            if (!$parsedFileID) {
                continue;
            }

            if ($redirect = $this->searchForTuple($parsedFileID, $filesystem, false)) {
                return $redirect;
            }

            // If our helper managed to parse the file id, but could not resolve to an actual physical file,
            // there's nothing else we can do.
            return null;
        }
    }

    public function resolveFileIDToLatest($fileID, Filesystem $filesystem)
    {
        // If File is not versionable, let's bail
        if (!class_exists(Versioned::class) || !File::has_extension(Versioned::class)) {
            return null;
        }

        foreach ($this->resolutionFileIDHelpers as $fileIDHelper) {
            $parsedFileID = $fileIDHelper->parseFileID($fileID);
            if (!$parsedFileID) {
                continue;
            }

            $hash = $parsedFileID->getHash();

            $tuple = $hash ? $this->resolveWithHash($parsedFileID) : $this->resolveHashless($parsedFileID);

            var_dump(get_class($fileIDHelper));
            var_dump($tuple);

            if ($tuple && $redirect = $this->searchForTuple($tuple, $filesystem, false)) {
                return $redirect;
            }

            // If our helper managed to parse the file id, but could not resolve to an actual physical file,
            // there's nothing else we can do.
            return null;
        }
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
            $versionFilters['WasPublished'] = true;
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
    }

    public function searchForTuple($tuple, Filesystem $filesystem, $strict = true)
    {
        $parsedFileID = $this->preProcessTuple($tuple);
        $helpers = $this->getResolutionFileIDHelpers();
        array_unshift($helpers, $this->getDefaultFileIDHelper());

        $enforceHash = $strict && $parsedFileID->getHash();

        foreach ($helpers as $helper) {
            $fileID = $helper->buildFileID(
                $parsedFileID->getFilename(),
                $parsedFileID->getHash(),
                $parsedFileID->getVariant()
            );
            if ($filesystem->has($fileID)) {
                if ($enforceHash && !$this->validateHash($helper, $parsedFileID, $filesystem)) {
                    // We found a file, but its hash doesn't match the hash of our tuple.
                     continue;
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

        // Re build the file ID but without the variant
        $fileID = $helper->buildFileID(
            $parsedFileID->getFilename(),
            $parsedFileID->getHash()
        );

        // Couldn't find the original file, let's bail.
        if (!$filesystem->has($fileID)) {
            return false;
        }

        // Check if the physical hash of the file starts with our parsed file ID hash
        $actualHash = sha1($filesystem->read($fileID));
        return strpos($actualHash, $parsedFileID->getHash()) === 0;
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
        foreach ($helpers as $helperToTry) {
            $fileID = $helperToTry->buildFileID(
                $parsedFileID->getFilename(),
                $parsedFileID->getHash()
            );
            if ($filesystem->has($fileID)) {
                $helper = $helperToTry;
                break;
            }
        }

        if (!$helper) {
            // Could not find the file,
            return null;
        }

        $folder = $helper->lookForVariantIn($parsedFileID);
        $possibleVariants = $filesystem->listContents($folder, true);
        foreach ($possibleVariants as $possibleVariant) {
            if ($possibleVariant['type'] !== 'dir' && $helper->isVariantOf($possibleVariant['path'], $parsedFileID)) {
                yield $parsedFileID->setFileID($possibleVariant['path']);
            }
        }
    }
}

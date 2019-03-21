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

    public function resolveFileID($fileID, Filesystem $adapter)
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
            if ($tuple && $redirect = $this->searchForTuple($tuple, $adapter)) {
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
     * @return array|null
     */
    private function resolveWithHash(ParsedFileID $parsedFileID)
    {
        // Try to find a version for a given stage
        $stage = Versioned::get_stage();
        Versioned::set_stage($this->getVersionedStage());
        $file = File::get()->filter(['FileFilename' => $parsedFileID->getFilename()])->first();
        Versioned::set_stage($stage);

        // Could not find a valid file, let's bail.
        if (!$file) {
            return null;
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
            return [
                'Filename' => $file->getFilename(),
                'Hash' => $file->getHash(),
                'Variant' => $parsedFileID->getVariant()
            ];
        }
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
        $stage = Versioned::get_stage();
        Versioned::set_stage($this->getVersionedStage());
        $file = File::get()->filter(['FileFilename' => $filename])->first();
        Versioned::set_stage($stage);

        if ($file) {
            return [
                'Filename' => $filename,
                'Hash' => $file->getHash(),
                'Variant' => $variant
            ];
        }
    }

    /**
     * Given a file tuple, try to find it on this adapter using one of the supported format.
     * @param array $tuple
     * @return null
     */
    public function searchForTuple($tuple, Filesystem $adapter)
    {
        // Pre-format our tuple
        if (is_array($tuple)) {
            $tupleData = $tuple;
        } elseif ($tuple instanceof ParsedFileID) {
            $tupleData = $tuple->getTuple();
        } else {
            throw new \InvalidArgumentException(
                'AssetAdapter::hashForTuple expect $tuples to be an array or a ParsedFileID'
            );
        }
        $filename = $tupleData['Filename'];
        $hash = $tupleData['Hash'];
        $variant = $tupleData['Variant'];


        $helpers = $this->getResolutionFileIDHelpers();
        array_unshift($helpers, $this->getDefaultFileIDHelper());

        foreach ($helpers as $helper) {
            $fileID = $helper->buildFileID($filename, $hash, $variant);
            if ($adapter->has($fileID)) {
                return $fileID;
            }
        }
        return null;
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
    public function setResolutionFileIDHelpers($resolutionFileIDHelpers)
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
}

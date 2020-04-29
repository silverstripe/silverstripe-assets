<?php

namespace App\FileMigration\TagsToShortCode\Legacy;

use League\Flysystem\Filesystem;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\Queries\SQLSelect;

class Strategy extends FileIDHelperResolutionStrategy
{

    /**
     * Code taken from @see FileIDHelperResolutionStrategy::resolveFileID()
     *
     * @param string $fileID
     * @param Filesystem $filesystem
     * @return ParsedFileID|null
     */
    public function resolveFileID($fileID, Filesystem $filesystem) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        foreach ($this->getResolutionFileIDHelpers() as $fileIDHelper) {
            $parsedFileID = $fileIDHelper->parseFileID($fileID);

            if (!$parsedFileID) {
                continue;
            }

            $filename = $parsedFileID->getFilename();
            $filename = $this->fixFilename($filename);
            $parsedFileID = $parsedFileID->setFilename($filename);

            $foundTuple = $this->searchForTuple($parsedFileID, $filesystem, true);

            if ($foundTuple) {
                return $foundTuple;
            }
        }

        // If we couldn't resolve the file ID, we bail
        return null;
    }

    /**
     * Fetch correct filename from database instead of relying on filename from asset reference
     * this fixes case sensitivity errors
     *
     * @param string $filename
     * @return string
     */
    private function fixFilename(string $filename): string
    {
        if (!$filename) {
            return $filename;
        }

        $query = SQLSelect::create(
            '"FileFilename"',
            '"File"',
            sprintf('LCASE("FileFilename") = %s', Convert::raw2sql(mb_strtolower($filename), true)),
            ['"ID"' => 'ASC'],
            [],
            [],
            1
        );

        $results = $query->execute();
        $result = $results->first();

        if (!array_key_exists('FileFilename', $result) || !$result['FileFilename']) {
            return $filename;
        }

        return (string) $result['FileFilename'];
    }
}

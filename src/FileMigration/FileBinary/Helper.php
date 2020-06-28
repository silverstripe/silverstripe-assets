<?php

namespace App\FileMigration\FileBinary;

use Generator;
use SilverStripe\Assets\Dev\Tasks\FileMigrationHelper;
use SilverStripe\Assets\File;
use SilverStripe\ORM\DataList;

class Helper extends FileMigrationHelper
{

    /**
     * @var array
     */
    private $ids = [];

    public function setIds(array $ids): self
    {
        $this->ids = $ids;

        return $this;
    }

    public function getFileQuery(): DataList
    {
        // public scope change
        return parent::getFileQuery();
    }

    /**
     * Code taken from @see FileMigrationHelper::chunk()
     *
     * @param DataList $query
     * @return Generator
     */
    protected function chunk(DataList $query): Generator
    {
        // only select specified files
        $query = $query->byIDs($this->ids);

        // the rest of the code is just a copy from base
        $chunkSize = 100;
        $greaterThanID = 0;
        $query = $query->limit($chunkSize)->sort('ID');

        while ($chunk = $query->filter('ID:GreaterThan', $greaterThanID)) {
            /** @var File $file */
            foreach ($chunk as $file) {
                yield $file;
            }

            if ($chunk->count() === 0) {
                break;
            }

            $greaterThanID = $file->ID;
        }
    }
}

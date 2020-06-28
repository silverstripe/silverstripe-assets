<?php

namespace App\FileMigration\TagsToShortCode;

use SilverStripe\Assets\Dev\Tasks\TagsToShortcodeHelper;
use SilverStripe\Assets\File;
use SilverStripe\ORM\Queries\SQLSelect;

class Helper extends TagsToShortcodeHelper
{

    /**
     * Copy of code from @see TagsToShortcodeHelper::updateTable()
     *
     * The full text search condition got replaced with ID condition
     *
     * @param string $table
     * @param string $field
     * @param array $ids
     */
    public function updateTable(string $table, string $field, array $ids): void
    {
        if (count($ids) === 0) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));

        $query = SQLSelect::create(
            ['"ID"', sprintf('"%s"', $field)],
            sprintf('"%s"', $table),
            ['"ID" IN (' . $placeholders . ')' => $ids]
        );

        $records = $query->execute();
        $this->rewriteFieldForRecords($records, $table, $field);
    }

    public function getFieldMap(// phpcs:ignore SlevomatCodingStandard.TypeHints
        $baseClass,
        $includeBaseClass,
        $fieldNames
    ): array {
        // public scope change
        return parent::getFieldMap($baseClass, $includeBaseClass, $fieldNames);
    }

    /**
     * Override for @see TagsToShortcodeHelper::updateTagFromFile()
     *
     * Remove empty attributes
     * this remedies the issue with WYSIWYG editor not rendering assets with empty attribute
     *
     * @param string $tag
     * @param File $file
     * @return string
     */
    protected function updateTagFromFile($tag, File $file)// phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        return str_replace(['title=""', 'alt=""'], ['', ''], $tag);
    }
}

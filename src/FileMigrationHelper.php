<?php

/**
 * @see FileMigrationHelper class
 * @deprecated 1.12.0 Will be removed without equivalent functionality to replace it
 */
use SilverStripe\Assets\Dev\Tasks\FileMigrationHelper;

if (!class_exists('SilverStripe\\Assets\\FileMigrationHelper')) {
    class_alias(FileMigrationHelper::class, 'SilverStripe\\Assets\\FileMigrationHelper');
}

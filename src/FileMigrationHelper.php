<?php

use SilverStripe\Assets\Dev\Tasks\FileMigrationHelper;

if (!class_exists('SilverStripe\\Assets\\FileMigrationHelper')) {
    class_alias(FileMigrationHelper::class, 'SilverStripe\\Assets\\FileMigrationHelper');
}

<?php

namespace SilverStripe\Assets;

/**
 * Service to help migrate File dataobjects to the new APL.
 *
 * This service does not alter these records in such a way that prevents downgrading back to 3.x
 *
 * @deprecated 1.4.0 Use \SilverStripe\Assets\Dev\Tasks\FileMigrationHelper instead
 */
class FileMigrationHelper extends \SilverStripe\Assets\Dev\Tasks\FileMigrationHelper
{
}

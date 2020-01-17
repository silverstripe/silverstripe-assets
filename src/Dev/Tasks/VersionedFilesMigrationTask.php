<?php

namespace SilverStripe\Assets\Dev\Tasks;

use SilverStripe\Assets\Dev\VersionedFilesMigrator;
use SilverStripe\Dev\BuildTask;

class VersionedFilesMigrationTask extends BuildTask
{
    const STRATEGY_DELETE = 'delete';

    const STRATEGY_PROTECT = 'protect';

    private static $segment = 'migrate-versionedfiles';

    protected $title = 'Migrate versionedfiles';

    protected $description = 'If you had the symbiote/silverstripe-versionedfiles module installed on your 3.x site, it 
        is no longer needed in 4.x as this functionality is provided by default. This task will remove the old _versions
        folders or protect them, depending on the strategy you use. Use ?strategy=delete or ?strategy=protect (Apache 
        only). [Default: delete]';

    /**
     * @param HTTPRequest $request
     */
    public function run($request)
    {
        $strategy = $request->getVar('strategy') ?: self::STRATEGY_DELETE;
        $migrator = VersionedFilesMigrator::create($strategy, ASSETS_PATH, true);
        $migrator->migrate();
    }
}

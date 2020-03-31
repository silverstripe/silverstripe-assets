<?php

namespace SilverStripe\Assets\Dev\Tasks;

use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Dev\BuildTask;

/**
 * A task to manually flush InterventionBackend cache
 */
class InterventionBackendCacheFlushTask extends BuildTask
{
    private static $segment = 'InterventionBackendCacheFlushTask';

    protected $title = 'Clear InterventionBackend cache';

    protected $description = "Clears caches for InterventionBackend";

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     * @throws \ReflectionException
     */
    public function run($request)
    {
        $class = new InterventionBackend();
        $class->getCache()->clear();

        echo 'DONE';
    }
}

<?php

namespace SilverStripe\Assets\Dev\Tasks;

use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * A task to manually flush InterventionBackend cache
 */
class InterventionBackendCacheFlushTask extends BuildTask
{
    protected static string $commandName = 'InterventionBackendCacheFlushTask';

    protected string $title = 'Clear InterventionBackend cache';

    protected static string $description = 'Clears caches for InterventionBackend';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $class = new InterventionBackend();
        $class->getCache()->clear();
        return Command::SUCCESS;
    }
}

<?php

namespace SilverStripe\Assets\Dev;

use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Path;
use SilverStripe\Dev\BuildTask;
use InvalidArgumentException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class VersionedFilesMigrator
{
    use Injectable;

    const STRATEGY_DELETE = 'delete';

    const STRATEGY_PROTECT = 'protect';

    /**
     * @var array
     */
    private static $dependencies = [
        'finder' => '%$' . Finder::class,
    ];

    /**
     * @var Finder
     */
    private $finder;

    /**
     * @var string
     */
    private $basePath = ASSETS_DIR;

    /**
     * @var string
     */
    private $strategy = self::STRATEGY_DELETE;

    /**
     * @var bool
     */
    private $showOutput = true;

    /**
     * List of logged messages, if $showOutput is false
     * @var array
     */
    private $log = [];

    /**
     * VersionedFilesMigrationTask constructor.
     * @param string $strategy
     * @param string $basePath
     * @param bool $output
     */
    public function __construct($strategy = self::STRATEGY_DELETE, $basePath = ASSETS_DIR, $output = true)
    {
        if (!in_array($strategy, [self::STRATEGY_DELETE, self::STRATEGY_PROTECT])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid strategy: %s',
                $strategy
            ));
        }
        $this->basePath = $basePath;
        $this->strategy = $strategy;
        $this->showOutput = $output;
    }

    /**
     * @return void
     */
    public function migrate()
    {
        if ($this->strategy === self::STRATEGY_PROTECT) {
            $this->doProtect();
        } else {
            $this->doDelete();
        }
    }

    /**
     * @return void
     */
    private function doProtect()
    {
        foreach ($this->getVersionDirectories() as $path) {
            $htaccessPath = Path::join($path, '.htaccess');
            if (!file_exists($htaccessPath)) {
                $content = "Require all denied";
                @file_put_contents($htaccessPath, $content);
                if (file_exists($htaccessPath)) {
                    $this->output("Added .htaccess file to $htaccessPath");
                } else {
                    $this->output("Failed to add .htaccess file to $htaccessPath");
                }
            }
        }
    }

    /**
     * @return void
     */
    private function doDelete()
    {
        foreach ($this->getVersionDirectories() as $path) {
            if (!is_dir($path)) {
                continue;
            }

            Filesystem::removeFolder($path);

            if (!is_dir($path)) {
                $this->output("Deleted $path");
            } else {
                $this->output("Failed to delete $path");
            }
        }
    }

    /**
     * @return array
     */
    private function getVersionDirectories()
    {
        $results = $this
            ->getFinder()
            ->directories()
            ->name('_versions')
            ->in($this->basePath);

        $folders = [];

        /* @var SplFileInfo $result */
        foreach ($results as $result) {
            $folders[] = $result->getPathname();
        }

        return $folders;
    }

    /**
     * @return string
     */
    private function nl()
    {
        return Director::is_cli() ? PHP_EOL : "<br />";
    }

    /**
     * @param string $msg
     */
    private function output($msg)
    {
        if ($this->showOutput) {
            echo $msg . $this->nl();
        } else {
            $this->log[] = $msg;
        }
    }

    /**
     * @param Finder $finder
     * @return $this
     */
    public function setFinder(Finder $finder)
    {
        $this->finder = $finder;

        return $this;
    }

    /**
     * @return Finder
     */
    public function getFinder()
    {
        return $this->finder;
    }

    /**
     * @return array
     */
    public function getLog()
    {
        return $this->log;
    }
}

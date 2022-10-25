<?php

namespace SilverStripe\Assets\Dev\Tasks;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use League\Flysystem\Filesystem;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\Queries\SQLSelect;

/**
 * Removes stray .htaccess files created through the silverstripe/secureassets module
 * on a 3.x-based site. The 4.x protections work differently:
 * One central assets/.htaccess file routes non-existent paths through SilverStripe,
 * which can choose to return a file from assets/.protected.
 * Any additional .htaccess files in folders can interfere with this logic.
 *
 * Note that this task does not migrate file metadata added/managed through silverstripe/secureassets.
 * The metadata fields are the same in 4.x (File.CanViewType etc).
 *
 * See https://github.com/silverstripe/silverstripe-assets/issues/231
 *
 * @deprecated 1.12.0 Will be removed without equivalent functionality to replace it
 */
class SecureAssetsMigrationHelper
{
    use Injectable;
    use Configurable;

    /**
     * @var array
     */
    protected $htaccessRegexes = [];

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class . '.quiet',
    ];

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct()
    {
        Deprecation::notice('1.12.0', 'Will be removed without equivalent functionality to replace it', Deprecation::SCOPE_CLASS);

        $this->logger = new NullLogger();

        $this->htaccessRegexes = [
            '#RewriteEngine On#',
            '#RewriteBase .*#', // allow any base folder
            '#' . preg_quote('RewriteCond %{REQUEST_URI} ^(.*)$') . '#',
            '#RewriteRule .*' . preg_quote('main.php?url=%1 [QSA]') . '#' // allow any framework base path
        ];
    }

    /**
     * Perform migration
     *
     * @param FlysystemAssetStore $store
     * @return array Folders which needed migration
     */
    public function run(FlysystemAssetStore $store)
    {
        $migrated = [];

        // There's no way .htaccess files could've been created by silverstripe/secureassets
        // in a protected filesystem (didn't exist in 3.x)
        $filesystem = $store->getPublicFilesystem();

        // The presence of secured folders can either come
        // from a freshly migrated 3.x database with silverstripe/secureassets,
        // or from an already used 4.x database with built-in asset protections.
        // Because the module itself has been removed in 4.x installs,
        // we can no longer tell the difference between those cases.
        $fileTable = DataObject::getSchema()->baseDataTable(File::class);
        $securedFolders = SQLSelect::create()
            ->setFrom("\"$fileTable\"")
            ->setSelect([
                '"ID"',
                '"FileFilename"',
            ])
            ->addWhere([
                '"ClassName" = ?' => Folder::class,
                // We don't need to check 'Inherited' permissions,
                // since Apache applies parent .htaccess and the module doesn't create them in this case.
                // See SecureFileExtension->needsAccessFile()
                '"CanViewType" IN(?,?)' => ['LoggedInUsers', 'OnlyTheseUsers']
            ]);

        if (!$securedFolders->count()) {
            $this->logger->info('No need for secure files migration');
            return [];
        }

        foreach ($securedFolders->execute()->map() as $id => $path) {
            /** @var Folder $folder */
            if (!$folder = Folder::get()->byID($id)) {
                $this->logger->warning(sprintf('No Folder record found for ID %d. Skipping', $id));
                continue;
            }

            $migratedPath = $this->migrateFolder($filesystem, $folder->getFilename());

            if ($migratedPath) {
                $migrated[] = $migratedPath;
            }
        }

        return $migrated;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * A "somewhat exact" match on file contents,
     * to avoid deleting any customised files.
     * Checks each line in a file, with some leeway
     * for different base folders which are dynamically generated
     * based on the context of a particular environment.
     *
     * @param string $content
     * @return bool
     */
    public function htaccessMatch($content)
    {
        $regexes = $this->htaccessRegexes;
        $lines = explode("\n", $content ?? '');

        if (count($lines ?? []) != count($regexes ?? [])) {
            return false;
        }

        foreach ($lines as $i => $line) {
            if (!preg_match($regexes[$i] ?? '', $line ?? '')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Filesystem $filesystem
     * @param string $path
     * return string|null The path of the migrated file (if successful)
     */
    protected function migrateFolder(Filesystem $filesystem, $path)
    {
        $htaccessPath = $path . '.htaccess';

        if (!$filesystem->has($htaccessPath)) {
            return null;
        }

        $content = $filesystem->read($htaccessPath);

        if ($this->htaccessMatch($content)) {
            $filesystem->delete($htaccessPath);
            $this->logger->debug(sprintf(
                'Removed obsolete secureassets .htaccess at %s',
                $path
            ));
            return $htaccessPath;
        } else {
            $this->logger->warning(sprintf(
                'Skipped non-standard htaccess file (not generated by secureassets?): %s',
                $path
            ));
            return null;
        }
    }
}

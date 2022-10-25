<?php
namespace SilverStripe\Assets\Dev\Tasks;

use Exception;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemInterface;
use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SebastianBergmann\Version;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * Service to help identify and migrate Files that have been saved to the wrong asset store and restore them to
 * the appropriate physical location.
 *
 * This is meant to correct files that got save to the wrong location following the CVE-2019-12245 vulnerability.
 *
 * @deprecated 1.12.0 Will be removed without equivalent functionality to replace it
 */
class NormaliseAccessMigrationHelper
{
    use Injectable;
    use Configurable;

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class . '.quiet',
    ];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * List of error messages
     * @var string[]
     */
    private $errors = [];


    /**
     * @var FlysystemAssetStore
     */
    private $store;

    /**
     * Initial value of `keep_empty_dirs` on asset store. This value is restored at the end of the task.
     * @var bool|null
     */
    private $initial_keep_empty_dirs;

    /**
     * List of folders to analyse at the end of the task to see if they can be deleted.
     * @var string[]
     */
    private $public_folders_to_truncate = [];

    /**
     * List of cached live versions of files by ID. Methods design to loop over a chunk of result set. This cache can
     * be pre-filleds cache to avoid fetching individual Live versions.
     * @var File[]
     */
    private $cached_live_files = [];


    /**
     * Prefix to remove from URL when truncating folders. Only used for testing.
     * @var string
     */
    private $basePath = '';

    private $folderClasses;

    /**
     * @param string $base Prefix for URLs. Only used for unit tests.
     */
    public function __construct($base = '')
    {
        Deprecation::notice('1.12.0', 'Will be removed without equivalent functionality to replace it', Deprecation::SCOPE_CLASS);
        $this->logger = new NullLogger();
        if ($base) {
            $this->basePath = $base;
        }
        $this->folderClasses = ClassInfo::subclassesFor(Folder::class, true);
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

    private function info($message)
    {
        $this->logger && $this->logger->info($message);
    }

    private function notice($message)
    {
        $this->logger && $this->logger->notice($message);
    }

    private function error($message)
    {
        $this->logger && $this->logger->error($message);
    }

    private function debug($message)
    {
        $this->logger && $this->logger->debug($message);
    }

    private function warning($message)
    {
        $this->logger && $this->logger->warning($message);
    }

    /**
     * Perform migration
     *
     * @return int[] Number of files successfully migrated
     */
    public function run()
    {
        // Set max time and memory limit
        Environment::increaseTimeLimitTo();
        Environment::setMemoryLimitMax(-1);
        Environment::increaseMemoryLimitTo(-1);
        $this->clearPermissionCheckerCache();

        $this->errors = [];

        /** @var FlysystemAssetStore $store */
        $this->store = Injector::inst()->get(AssetStore::class);

        $step = 1;
        $totalStep = 4;

        // Step 1 - Finding files
        $this->notice(sprintf('Step %d/%d: Finding files with bad access permission', $step, $totalStep));
        $badFiles = $this->findBadFiles();

        if (empty($badFiles)) {
            if (empty($this->errors)) {
                $this->notice('All your files have the correct access permission.');
            } else {
                $this->warning(sprintf(
                    'Could not find any files with incorrect access permission, but %d errors were found.',
                    sizeof($this->errors ?? [])
                ));
            }

            return [
                'total' => 0,
                'success' => 0,
                'fail' => sizeof($this->errors ?? [])
            ];
        }

        $this->notice(sprintf('%d files with bad access permission have been found.', sizeof($badFiles ?? [])));

        $step++;

        // Step 2 - Tweak asset store setting so we don't try deleting empty folders while moving files around
        $this->notice(sprintf('Step %d/%d: Enabling "keep empty dirs" flag', $step, $totalStep));
        $this->disableKeepEmptyDirs();

        $step++;

        // Step 3 - Move files to their correct spots
        $this->notice(sprintf('Step %d/%d: Correct files with bad access permission', $step, $totalStep));
        $badFileIDs = array_keys($badFiles ?? []);
        unset($badFiles);

        $success = 0;
        $fail = 0;
        $count = sizeof($badFileIDs ?? []);
        foreach ($this->loopBadFiles($badFileIDs) as $file) {
            try {
                if ($this->fix($file)) {
                    $success++;
                }
            } catch (Exception $ex) {
                $this->error($ex->getMessage());
                $this->errors[] = $ex->getMessage();
            }
        }

        $step++;

        // Step 4 - Delete empty folders and re-enable normal logic to delete empty folders
        $this->notice(sprintf('Step %d/%d: Clean up', $step, $totalStep));
        $this->cleanup();

        // Print out some summary info
        if ($this->errors) {
            $this->warning(sprintf('Completed with %d errors', sizeof($this->errors ?? [])));
            foreach ($this->errors as $error) {
                $this->warning($error);
            }
        } else {
            $this->notice('All files with bad access permission have been fixed');
        }

        return [
            'total' => $count,
            'success' => $success,
            'fail' => sizeof($this->errors ?? [])
        ];
    }

    /**
     * Need to clear the Inherited Permission cache for files to make sure we don't get stale permissions.
     */
    private function clearPermissionCheckerCache()
    {
        $permissionChecker = File::singleton()->getPermissionChecker();
        if (method_exists($permissionChecker, 'clearCache')) {
            $permissionChecker->clearCache();
        }
        if (method_exists($permissionChecker, 'flushMemberCache')) {
            $permissionChecker->flushMemberCache();
        }
    }

    /**
     * Stash the initial value of `keep_empty_dirs` on the current asset store
     */
    private function disableKeepEmptyDirs()
    {
        $this->public_folders_to_truncate = [];
        $store = $this->store;

        if ($store instanceof FlysystemAssetStore) {
            $this->initial_keep_empty_dirs =  $store::config()->get('keep_empty_dirs');
            $store::config()->set('keep_empty_dirs', true);
            $this->notice($this->initial_keep_empty_dirs ?
                '`keep_empty_dirs` on AssetStore is already enabled' :
                'Enabling `keep_empty_dirs` on AssetStore');
        } else {
            $this->notice('AssetStore does not support keep_empty_dirs. Skipping.');
        }
    }

    /**
     * Delete the empty folders that would have normally been deleted when moving files if `keep_empty_dirs` had
     * been disabled and restore the initial `keep_empty_dirs` on the asset store.
     */
    private function cleanup()
    {
        // If the asset store wasn't disabled when we started, there's nothing to do.
        if ($this->initial_keep_empty_dirs !== false) {
            $this->notice('AssetStore\'s keep_empty_dirs was not changed. Skipping.');
            return;
        }

        $fs = $this->store->getPublicFilesystem();
        $truncatedPaths = [];
        $this->notice('Truncating empty folders');

        // Sort list of folders so that the deeper ones are processed first
        usort($this->public_folders_to_truncate, function ($a, $b) {
            $aCrumbs = explode('/', $a ?? '');
            $bCrumbs = explode('/', $b ?? '');
            if (sizeof($aCrumbs ?? []) > sizeof($bCrumbs ?? [])) {
                return -1;
            }
            if (sizeof($aCrumbs ?? []) < sizeof($bCrumbs ?? [])) {
                return 1;
            }
            return 0;
        });

        foreach ($this->public_folders_to_truncate as $path) {
            if ($this->canBeTruncated($path, $fs)) {
                $this->info(sprintf('Deleting empty folder %s', $path));
                $fs->deleteDir($path);
                $truncatedPaths[] = $path;
            }
        }

        foreach ($truncatedPaths as $path) {
            // For each folder that we've deleted, recursively check if we can now delete its parent.
            $this->recursiveTruncate(dirname($path ?? ''), $fs);
        }

        // Restore initial config
        $store = $this->store;
        $store::config()->set('keep_empty_dirs', false);
    }

    /**
     * Check if the provided folder can be deleted on this Filesystem
     * @param string $path
     * @param FilesystemInterface $fs
     * @return bool
     */
    private function canBeTruncated($path, FilesystemInterface $fs)
    {
        if (!$fs->has($path)) {
            // The folder doesn't exists
            return false;
        }

        $contents = $fs->listContents($path);

        foreach ($contents as $content) {
            if ($content['type'] !== 'dir') {
                // if there's a file in the folder, we can't delete it
                return false;
            }
        }

        // Lookup into each subfolder to see if they contain files.
        foreach ($contents as $content) {
            if (!$this->canBeTruncated($content['path'], $fs)) {
                // At least one sub directory contains files
                return false;
            }
        }

        return true;
    }

    /**
     * Delete this folder if it doesn't contain any files and parent folders if they don't contain any files either.
     * @param string $path
     * @param FilesystemInterface $fs
     */
    private function recursiveTruncate($path, FilesystemInterface $fs)
    {
        if ($path && ltrim($path ?? '', '.') && empty($fs->listContents($path))
        ) {
            $this->info(sprintf('Deleting empty folder %s', $path));
            $fs->deleteDir($path);
            $this->recursiveTruncate(dirname($path ?? ''), $fs);
        }
    }

    /**
     * Find the parent folder for this file and add it to the list of folders to check for possible deletion at the
     * end of the task.
     * @param File $file
     */
    private function markFolderForTruncating(File $file)
    {
        if ($this->initial_keep_empty_dirs !== false) {
            return;
        }

        $url = $file->getSourceURL(false);

        if ($this->basePath && strpos($url ?? '', $this->basePath ?? '') === 0) {
            // This bit is only relevant for unit test because our URL will be prefixied with the TestAssetStore path
            $url = substr($url ?? '', strlen($this->basePath ?? ''));
        }

        $url = trim($url ?? '', '/');

        if (strpos($url ?? '', ASSETS_DIR . '/') === 0) {
            // Remove the assets prefix
            $url = substr($url ?? '', strlen(ASSETS_DIR . '/'));
        }

        $folderPath = trim(dirname($url ?? ''), '/');


        if (!in_array($folderPath, $this->public_folders_to_truncate ?? [])) {
            $this->public_folders_to_truncate[] = $folderPath;
        }
    }

    /**
     * Loop through all the files and find the ones that aren't stored in the correct store.
     *
     * Returns an array of bit masks with the ID of the file has the key.
     * @return array
     */
    public function findBadFiles()
    {
        $operations = [];
        foreach ($this->loopFiles() as $file) {
            try {
                $ops = $this->needToMove($file);
                if ($ops) {
                    $operations[$file->ID] = $ops;
                }
            } catch (Exception $ex) {
                $this->error($ex->getMessage());
                $this->errors[] = $ex->getMessage();
            }
        }

        foreach ($operations as &$ops) {
            $ops = array_filter($ops ?? [], function ($storeToMove) {
                // We only keep operation that involvs protecting files for now.
                return $storeToMove === AssetStore::VISIBILITY_PROTECTED;
            });
        }

        return array_filter($operations ?? [], function ($ops) {
            return !empty($ops);
        });
    }

    /**
     * Loop over the files in chunks to save memory.
     * @return Generator|File[]
     */
    private function loopFiles()
    {
        $limit = 100;
        $offset = 0;
        $count = 0;

        do {
            $count = 0;
            $files = File::get()
                ->limit($limit, $offset)
                ->sort('ID')
                ->exclude('ClassName', $this->folderClasses);

            $IDs = $files->getIDList();
            $this->preCacheLiveFiles($IDs);

            foreach ($files as $file) {
                yield $file;
                $count++;
            }
            $offset += $limit;
        } while ($count === $limit);
    }

    /**
     * Make sure all versions of the povided file are stored in the correct asset store.
     * @param File $file
     * @return bool Whether the fix was completed succesfully
     */
    public function fix(File $file)
    {
        $success = true;
        $actions = $this->needToMove($file);

        // Make sure restricted live files are protected
        if (isset($actions[Versioned::LIVE]) && $actions[Versioned::LIVE] === AssetStore::VISIBILITY_PROTECTED) {
            $liveFile = $this->getLive($file);
            $this->markFolderForTruncating($liveFile);
            if ($liveFile) {
                $liveFile->protectFile();
                $this->info(sprintf('Protected live file %s', $liveFile->getFilename()));
            } else {
                $message = sprintf('Could not protected live file %s', $file->getFilename());
                $success = false;
                $this->error($message);
                $this->errors[] = $message;
            }
        }

        // Make sure draft files are protected
        if (isset($actions[Versioned::DRAFT]) && $actions[Versioned::DRAFT] === AssetStore::VISIBILITY_PROTECTED) {
            $this->markFolderForTruncating($file);
            $file->protectFile();
            $this->info(sprintf('Protected draft file %s', $file->getFilename()));
        }

        // Make sure unrestricted live files are public
        if (isset($actions[Versioned::LIVE]) && $actions[Versioned::LIVE] === AssetStore::VISIBILITY_PUBLIC) {
            $liveFile = $this->getLive($file);
            if ($liveFile) {
                $liveFile->publishFile();
                $this->info(sprintf('Published live file %s', $liveFile->getFilename()));
            } else {
                $message = sprintf('Could not published live file %s', $file->getFilename());
                $this->error($message);
                $success = false;
                $this->errors[] = $message;
            }
        }

        return $success;
    }

    /**
     * Return the live version of the provided file.
     * @param File $file
     * @return File|null
     */
    private function getLive(File $file)
    {
        $ID = $file->ID;

        // If we've pre-warmed the cache get the value from there
        if (isset($this->cached_live_file[$ID])) {
            return $this->cached_live_file[$ID];
        }

        if ($file->isLiveVersion()) {
            return $file;
        }

        $liveVersion = Versioned::get_versionnumber_by_stage(File::class, Versioned::LIVE, $ID);

        return $liveVersion ?
            Versioned::get_version(File::class, $ID, $liveVersion) :
            null ;
    }

    /**
     * Pre warm the live version cache by loading the live version of provided File IDs.
     * @param int[] $IDs
     */
    private function preCacheLiveFiles(array $IDs)
    {
        if (empty($IDs)) {
            return;
        }

        $cached = [];
        foreach ($IDs as $ID) {
            $cached[$ID] = null;
        }

        $liveFiles = Versioned
            ::get_by_stage(File::class, Versioned::LIVE)
            ->exclude('ClassName', $this->folderClasses)
            ->filter('ID', $IDs);


        foreach ($liveFiles as $file) {
            $cached[$file->ID] = $file;
        }

        $this->cached_live_file = $cached;
    }

    /**
     * Determine if the versions of the provided file are stored in the correct asset store.
     * @param File $draftFile
     * @throws InvalidArgumentException When the provided `$draftFile` is invalid
     * @throws LogicException When there's some unexpected condition with the file
     * @return int Bitmask for the operations to perform on the file
     */
    public function needToMove(File $draftFile)
    {
        $this->validateInputFile($draftFile);

        /** @var File $liveFile */
        $liveFile = $this->getLive($draftFile);

        // We only need to check permission on the draft file if both those conditions are true:
        // * live version is not the latest draft
        // * the draft filename or file hash have changed
        $checkDraftFile = !$liveFile || (
            $liveFile->Version !== $draftFile->Version &&
            (
                $draftFile->getFilename() !== $liveFile->getFilename() ||
                $draftFile->getHash() !== $liveFile->getHash()
            )
        );

        $moveOperations = [];

        if ($liveFile) {
            $moveOperations[Versioned::LIVE] = $this->needToMoveVersion($liveFile);
        }

        if ($checkDraftFile) {
            $moveOperations[Versioned::DRAFT] = $this->needToMoveVersion($draftFile);
        }

        return array_filter($moveOperations ?? []);
    }

    /**
     * Check if the specific file version provided needs to be move to a different store.
     * @param File $file
     * @return string|false
     */
    private function needToMoveVersion(File $file)
    {
        $visibility = $file->getVisibility();
        $this->validateVisibility($file, $visibility);
        $canView = Member::actAs(null, function () use ($file) {
            return $file->canView();
        });

        if (!$canView && $visibility === AssetStore::VISIBILITY_PUBLIC) {
            // File should be protected but is on the public store
            return AssetStore::VISIBILITY_PROTECTED;
        }

        if ($canView && $visibility === AssetStore::VISIBILITY_PROTECTED) {
            // File should be public but is on the protected store
            return AssetStore::VISIBILITY_PUBLIC;
        }

        return false;
    }

    /**
     * Make sure the provided file is suitable for process by needToMove.
     *
     * @param File $file
     * @throws InvalidArgumentException
     */
    private function validateInputFile(File $file)
    {
        if (in_array($file->ClassName, $this->folderClasses ?? [])) {
            throw new InvalidArgumentException(sprintf(
                '%s::%s(): Provided File can not be a Folder',
                __CLASS__,
                __METHOD__
            ));
        }
    }

    /**
     * Make sure the provided visibility is a valid visibility string from AssetStore.
     * @param File $file
     * @param string $visibility
     * @throws LogicException
     */
    private function validateVisibility(File $file, $visibility)
    {
        $validVisibilities = [AssetStore::VISIBILITY_PROTECTED, AssetStore::VISIBILITY_PUBLIC];
        if (!in_array($visibility, $validVisibilities ?? [])) {
            throw new LogicException(sprintf(
                '%s::%s(): File %s visibility of "%s" is invalid',
                __CLASS__,
                __METHOD__,
                $file->getFilename(),
                $visibility
            ));
        }
    }

    /**
     * Fetch the list of provided files in chunck from the DB
     * @param int[] $IDs
     * @return \Generator|File[]
     */
    private function loopBadFiles(array $IDs)
    {
        $limit = 100;
        $yieldCount = 0;
        $total = sizeof($IDs ?? []);

        $chunks = array_chunk($IDs ?? [], $limit ?? 0);
        unset($IDs);

        foreach ($chunks as $chunk) {
            $this->preCacheLiveFiles($chunk);

            foreach (File::get()->filter('ID', $chunk) as $file) {
                yield $file;
            }
            $yieldCount += sizeof($chunk ?? []);
            $this->notice(sprintf('Processed %d files out of %d', $yieldCount, $total));
        }
    }
}

<?php

namespace SilverStripe\Assets\Flysystem;

use Generator;
use InvalidArgumentException;
use League\Flysystem\Directory;
use League\Flysystem\Exception;
use League\Flysystem\Filesystem;
use League\Flysystem\Util;
use LogicException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Storage\AssetNameGenerator;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\AssetStoreRouter;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Asset store based on flysystem Filesystem as a backend
 */
class FlysystemAssetStore implements AssetStore, AssetStoreRouter, Flushable
{
    use Configurable;

    /**
     * Session key to use for user grants
     */
    const GRANTS_SESSION = 'AssetStore_Grants';

    /**
     * @var Filesystem
     */
    private $publicFilesystem = null;

    /**
     * Filesystem to use for protected files
     *
     * @var Filesystem
     */
    private $protectedFilesystem = null;

    /**
     * File resolution strategy to use with the public adapter.
     * @var FileResolutionStrategy
     */
    private $publicResolutionStrategy = null;

    /**
     * File resolution strategy to use with the protected adapter.
     * @var FileResolutionStrategy
     */
    private $protectedResolutionStrategy = null;

    /**
     * Enable to use legacy filename behaviour (omits hash and uses the natural filename).
     *
     * This setting was only required for SilverStripe prior to the 4.4.0 release.
     * This release re-introduced natural filenames as the default mode for public files.
     * See https://docs.silverstripe.org/en/4/developer_guides/files/file_migration/
     * and https://docs.silverstripe.org/en/4/changelogs/4.4.0/ for details.
     *
     * If you have migrated to 4.x prior to the 4.4.0 release with this setting turned on,
     * the setting won't have any effect starting with this release.
     *
     * If you have migrated to 4.x prior to the 4.4.0 release with this setting turned off,
     * we recommend that you run the file migration task as outlined
     * in https://docs.silverstripe.org/en/4/changelogs/4.4.0/
     *
     * Note that if using legacy filenames then duplicate files will not work.
     *
     * @config
     * @var bool
     */
    private static $legacy_filenames = false;

    /**
     * Flag if empty folders are allowed.
     * If false, empty folders are cleared up when their contents are deleted.
     *
     * @config
     * @var bool
     */
    private static $keep_empty_dirs = false;

    /**
     * Set HTTP error code for requests to secure denied assets.
     * Note that this defaults to 404 to prevent information disclosure
     * of secure files
     *
     * @config
     * @var int
     */
    private static $denied_response_code = 404;

    /**
     * Set HTTP error code to use for missing secure assets
     *
     * @config
     * @var int
     */
    private static $missing_response_code = 404;


    /**
     * Define the HTTP Response code for request that should be redirected to a different URL. Defaults to a temporary
     * redirection (302). Set to 308 if you would rather your redirections be permanent and indicate to search engine
     * that they should index the other file.
     * @config
     * @var int
     */
    private static $redirect_response_code = 302;

    /**
     * Custom headers to add to all custom file responses
     *
     * @config
     * @var array
     */
    private static $file_response_headers = array(
        'Cache-Control' => 'private'
    );

    /**
     * Assign new flysystem backend
     *
     * @param Filesystem $filesystem
     * @return $this
     */
    public function setPublicFilesystem(Filesystem $filesystem)
    {
        if (!$filesystem->getAdapter() instanceof PublicAdapter) {
            throw new InvalidArgumentException("Configured adapter must implement PublicAdapter");
        }
        $this->publicFilesystem = $filesystem;
        return $this;
    }

    /**
     * Get the currently assigned flysystem backend
     *
     * @return Filesystem
     * @throws LogicException
     */
    public function getPublicFilesystem()
    {
        if (!$this->publicFilesystem) {
            throw new LogicException("Filesystem misconfiguration error");
        }
        return $this->publicFilesystem;
    }

    /**
     * Assign filesystem to use for non-public files
     *
     * @param Filesystem $filesystem
     * @return $this
     */
    public function setProtectedFilesystem(Filesystem $filesystem)
    {
        if (!$filesystem->getAdapter() instanceof ProtectedAdapter) {
            throw new InvalidArgumentException("Configured adapter must implement ProtectedAdapter");
        }
        $this->protectedFilesystem = $filesystem;
        return $this;
    }

    /**
     * Get filesystem to use for non-public files
     *
     * @return Filesystem
     * @throws Exception
     */
    public function getProtectedFilesystem()
    {
        if (!$this->protectedFilesystem) {
            throw new Exception("Filesystem misconfiguration error");
        }
        return $this->protectedFilesystem;
    }

    /**
     * @return FileResolutionStrategy
     */
    public function getPublicResolutionStrategy()
    {
        if (!$this->publicResolutionStrategy) {
            $this->publicResolutionStrategy = Injector::inst()->get(FileResolutionStrategy::class . '.public');
        }

        if (!$this->publicResolutionStrategy) {
            throw new Exception("Filesystem misconfiguration error");
        }
        return $this->publicResolutionStrategy;
    }

    /**
     * @param FileResolutionStrategy $publicResolutionStrategy
     */
    public function setPublicResolutionStrategy($publicResolutionStrategy)
    {
        $this->publicResolutionStrategy = $publicResolutionStrategy;
    }

    /**
     * @return FileResolutionStrategy
     */
    public function getProtectedResolutionStrategy()
    {
        if (!$this->protectedResolutionStrategy) {
            $this->protectedResolutionStrategy = Injector::inst()->get(FileResolutionStrategy::class . '.protected');
        }

        if (!$this->protectedResolutionStrategy) {
            throw new Exception("Filesystem misconfiguration error");
        }
        return $this->protectedResolutionStrategy;
    }

    /**
     * @param FileResolutionStrategy $protectedResolutionStrategy
     */
    public function setProtectedResolutionStrategy($protectedResolutionStrategy)
    {
        $this->protectedResolutionStrategy = $protectedResolutionStrategy;
    }

    /**
     * Return the store that contains the given fileID
     *
     * @param string $fileID Internal file identifier
     * @deprecated 1.4.0
     * @return Filesystem
     */
    protected function getFilesystemFor($fileID)
    {
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs) {
                return fs;
            },
            $fileID
        );
    }

    /**
     * Generic method to apply an action to a file regardless of what FileSystem it's on. The action to perform should
     * be provided as a closure expecting the following signature:
     * ```
     * function(ParsedFileID $parsedFileID, FileSystem $fs, FileResolutionStrategy $strategy, $visibility)
     * ```
     *
     * `applyToFileOnFilesystem` will try to following steps and call the closure if they are succesfull:
     * 1. Look for the file on the public filesystem using the explicit fileID provided.
     * 2. Look for the file on the protected filesystem using the explicit fileID provided.
     * 3. Look for the file on the public filesystem using the public resolution strategy.
     * 4. Look for the file on the protected filesystem using the protected resolution strategy.
     *
     * If the closure returns `false`, `applyToFileOnFilesystem` will carry on and try the follow up steps.
     *
     * Any other value the closure returns (including `null`) will be returned to the calling function.
     *
     * @param callable $closure Action to apply.
     * @param string|array|ParsedFileID $fileID File identication. Can be a string, a file tuple or a ParsedFileID
     * @param bool $strictHashCheck
     * @return mixed
     */
    private function applyToFileOnFilesystem(callable $closure, $fileID, $strictHashCheck = true)
    {
        $publicSet = [
            $this->getPublicFilesystem(),
            $this->getPublicResolutionStrategy(),
            self::VISIBILITY_PUBLIC
        ];

        $protectedSet = [
            $this->getProtectedFilesystem(),
            $this->getProtectedResolutionStrategy(),
            self::VISIBILITY_PROTECTED
        ];

        // First we try
        foreach ([$publicSet, $protectedSet] as $set) {
            list($fs, $strategy, $visibility) = $set;
            $fileIdStr = is_string($fileID) ? $fileID : $strategy->buildFileID($fileID);
            if ($fs->has($fileIdStr)) {
                $response = $closure(
                    ($fileID instanceof ParsedFileID) ?
                        $fileID->setFileID($fileIdStr) :
                        $strategy->resolveFileID($fileIdStr, $fs),
                    $fs,
                    $strategy,
                    $visibility
                );
                if ($response !== false) {
                    return $response;
                }
            }
        }

        foreach ([$publicSet, $protectedSet] as $set) {
            list($fs, $strategy, $visibility) = $set;
            $parsedFileID = is_string($fileID) ?
                $strategy->resolveFileID($fileID, $fs) :
                $strategy->searchForTuple($fileID, $fs);
            if ($parsedFileID) {
                $response = $closure($parsedFileID, $fs, $strategy, $visibility);
                if ($response !== false) {
                    return $response;
                }
            }
        }

        return null;
    }

    public function getCapabilities()
    {
        return array(
            'visibility' => array(
                self::VISIBILITY_PUBLIC,
                self::VISIBILITY_PROTECTED
            ),
            'conflict' => array(
                self::CONFLICT_EXCEPTION,
                self::CONFLICT_OVERWRITE,
                self::CONFLICT_RENAME,
                self::CONFLICT_USE_EXISTING
            )
        );
    }

    public function getVisibility($filename, $hash)
    {
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs, FileResolutionStrategy $strategy, $visibility) {
                return $visibility;
            },
            new ParsedFileID($filename, $hash)
        );
    }

    public function getAsStream($filename, $hash, $variant = null)
    {
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, FileSystem $fs, FileResolutionStrategy $strategy, $visibility) {
                return $fs->readStream($parsedFileID->getFileID());
            },
            new ParsedFileID($filename, $hash, $variant)
        );
    }

    public function getAsString($filename, $hash, $variant = null)
    {
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, FileSystem $fs, FileResolutionStrategy $strategy, $visibility) {
                return $fs->read($parsedFileID->getFileID());
            },
            new ParsedFileID($filename, $hash, $variant)
        );
    }

    public function getAsURL($filename, $hash, $variant = null, $grant = true)
    {
        $tuple = new ParsedFileID($filename, $hash, $variant);

        // Check with filesystem this asset exists in
        $public = $this->getPublicFilesystem();
        $protected = $this->getProtectedFilesystem();

        if ($parsedFileID = $this->getPublicResolutionStrategy()->searchForTuple($tuple, $public)) {
            /** @var PublicAdapter $publicAdapter */
            $publicAdapter = $public->getAdapter();
            return $publicAdapter->getPublicUrl($parsedFileID->getFileID());
        }

        if ($parsedFileID = $this->getProtectedResolutionStrategy()->searchForTuple($tuple, $protected)) {
            if ($grant) {
                $this->grant($parsedFileID->getFilename(), $parsedFileID->getHash());
            }
            /** @var ProtectedAdapter $protectedAdapter */
            $protectedAdapter = $protected->getAdapter();
            return $protectedAdapter->getProtectedUrl($parsedFileID->getFileID());
        }

        $fileID = $this->getPublicResolutionStrategy()->buildFileID($tuple);
        /** @var PublicAdapter $publicAdapter */
        $publicAdapter = $public->getAdapter();
        return $publicAdapter->getPublicUrl($fileID);
    }

    public function setFromLocalFile($path, $filename = null, $hash = null, $variant = null, $config = array())
    {
        // Validate this file exists
        if (!file_exists($path)) {
            throw new InvalidArgumentException("$path does not exist");
        }

        // Get filename to save to
        if (empty($filename)) {
            $filename = basename($path);
        }

        // Callback for saving content
        $callback = function (Filesystem $fs, $fileID) use ($path) {
            // Read contents as string into flysystem
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new InvalidArgumentException("$path could not be opened for reading");
            }

            // If there's already a file where we want to write and that file has the same sha1 hash as our source file
            // We just let the existing file sit there pretend to have writen it. This avoid a weird edge case where
            // We try to move an existing file to its own location which causes us to override the file with zero bytes
            if ($fs->has($fileID) && $this->getStreamSHA1($handle) === $this->getStreamSHA1($fs->readStream($fileID))) {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                return true;
            }

            $result = $fs->putStream($fileID, $handle);
            return $result;
        };

        // When saving original filename, generate hash
        if (!$variant) {
            $hash = sha1_file($path);
        }

        // Submit to conflict check
        return $this->writeWithCallback($callback, $filename, $hash, $variant, $config);
    }

    public function setFromString($data, $filename, $hash = null, $variant = null, $config = array())
    {
        // Callback for saving content
        $callback = function (Filesystem $filesystem, $fileID) use ($data) {
            return $filesystem->put($fileID, $data);
        };

        // When saving original filename, generate hash
        if (!$variant) {
            $hash = sha1($data);
        }

        // Submit to conflict check
        return $this->writeWithCallback($callback, $filename, $hash, $variant, $config);
    }

    public function setFromStream($stream, $filename, $hash = null, $variant = null, $config = array())
    {
        // If the stream isn't rewindable, write to a temporary filename
        if (!$this->isSeekableStream($stream)) {
            $path = $this->getStreamAsFile($stream);
            $result = $this->setFromLocalFile($path, $filename, $hash, $variant, $config);
            unlink($path);
            return $result;
        }

        // Callback for saving content
        $callback = function (Filesystem $filesystem, $fileID) use ($stream) {
            return $filesystem->putStream($fileID, $stream);
        };

        // When saving original filename, generate hash
        if (!$hash && !$variant) {
            $hash = $this->getStreamSHA1($stream);
        }

        // Submit to conflict check
        return $this->writeWithCallback($callback, $filename, $hash, $variant, $config);
    }

    public function delete($filename, $hash)
    {
        $response = false;

        $this->applyToFileOnFilesystem(
            function (ParsedFileID $pfid, Filesystem $fs, FileResolutionStrategy $strategy) use (&$response) {
                $response = $this->deleteFromFileStore($pfid, $fs, $strategy) || $response;
                return false;
            },
            new ParsedFileID($filename, $hash)
        );

        return $response;
    }

    public function rename($filename, $hash, $newName)
    {
        if (empty($newName)) {
            throw new InvalidArgumentException("Cannot write to empty filename");
        }
        if ($newName === $filename) {
            return $filename;
        }

        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs, FileResolutionStrategy $strategy) use ($newName) {

                $destParsedFileID = $parsedFileID->setFilename($newName);

                // Move all variants around
                foreach ($strategy->findVariants($parsedFileID, $fs) as $originParsedFileID) {
                    $origin = $originParsedFileID->getFileID();
                    $destination = $strategy->buildFileID(
                        $destParsedFileID->setVariant($originParsedFileID->getVariant())
                    );
                    $fs->rename($origin, $destination);
                    $this->truncateDirectory(dirname($origin), $fs);
                }

                // Build and parsed non-variant file ID so we can figure out what the new name file name is
                $cleanFilename = $strategy->parseFileID(
                    $strategy->buildFileID($destParsedFileID)
                )->getFilename();

                return $cleanFilename;
            },
            new ParsedFileID($filename, $hash)
        );
    }

    public function copy($filename, $hash, $newName)
    {
        if (empty($newName)) {
            throw new InvalidArgumentException("Cannot write to empty filename");
        }
        if ($newName === $filename) {
            return $filename;
        }

        /** @var ParsedFileID $newParsedFiledID */
        $newParsedFiledID = $newParsedFiledID = $this->applyToFileOnFilesystem(
            function (ParsedFileID $pfid, Filesystem $fs, FileResolutionStrategy $strategy) use ($newName) {
                $newName = $strategy->cleanFilename($newName);
                foreach ($strategy->findVariants($pfid, $fs) as $variantParsedFileID) {
                    $fromFileID = $variantParsedFileID->getFileID();
                    $toFileID = $strategy->buildFileID($variantParsedFileID->setFilename($newName));
                    $fs->copy($fromFileID, $toFileID);
                }

                return $pfid->setFilename($newName);
            },
            new ParsedFileID($filename, $hash)
        );

        return $newParsedFiledID ? $newParsedFiledID->getFilename(): null;
    }

    /**
     * Delete the given file (and any variants) in the given {@see Filesystem}
     *
     * @param string $fileID
     * @param Filesystem $filesystem
     * @return bool True if a file was deleted
     * @deprecated 1.4.0
     */
    protected function deleteFromFilesystem($fileID, Filesystem $filesystem)
    {
        $deleted = false;
        foreach ($this->findVariants($fileID, $filesystem) as $nextID) {
            $filesystem->delete($nextID);
            $deleted = true;
        }

        // Truncate empty dirs
        $this->truncateDirectory(dirname($fileID), $filesystem);

        return $deleted;
    }


    /**
     * Delete the given file (and any variants) in the given {@see Filesystem}
     * @param ParsedFileID $parsedFileID
     * @param Filesystem $filesystem
     * @param FileResolutionStrategy $strategy
     * @return bool
     */
    protected function deleteFromFileStore(ParsedFileID $parsedFileID, Filesystem $fs, FileResolutionStrategy $strategy)
    {
        $deleted = false;
        /** @var ParsedFileID $parsedFileIDToDel */
        foreach ($strategy->findVariants($parsedFileID, $fs) as $parsedFileIDToDel) {
            $fs->delete($parsedFileIDToDel->getFileID());
            $deleted = true;
        }

        // Truncate empty dirs
        $this->truncateDirectory(dirname($parsedFileID->getFileID()), $fs);

        return $deleted;
    }

    /**
     * Clear directory if it's empty
     *
     * @param string $dirname Name of directory
     * @param Filesystem $filesystem
     */
    protected function truncateDirectory($dirname, Filesystem $filesystem)
    {
        if ($dirname
            && ltrim(dirname($dirname), '.')
            && !$this->config()->get('keep_empty_dirs')
            && !$filesystem->listContents($dirname)
        ) {
            $filesystem->deleteDir($dirname);
        }
    }

    /**
     * Returns an iterable {@see Generator} of all files / variants for the given $fileID in the given $filesystem
     * This includes the empty (no) variant.
     *
     * @param string $fileID ID of original file to compare with.
     * @param Filesystem $filesystem
     * @return Generator
     */
    protected function findVariants($fileID, Filesystem $filesystem)
    {
        $dirname = ltrim(dirname($fileID), '.');
        foreach ($filesystem->listContents($dirname) as $next) {
            if ($next['type'] !== 'file') {
                continue;
            }
            $nextID = $next['path'];
            // Compare given file to target, omitting variant
            if ($fileID === $this->removeVariant($nextID)) {
                yield $nextID;
            }
        }
    }

    public function publish($filename, $hash)
    {
        $parsedFileID = new ParsedFileID($filename, $hash);
        $protected = $this->getProtectedFilesystem();
        $public = $this->getPublicFilesystem();

        $expectedPublicFileID = $this->getPublicResolutionStrategy()->buildFileID($parsedFileID);

        $this->moveBetweenFileStore(
            $parsedFileID,
            $protected,
            $this->getProtectedResolutionStrategy(),
            $public,
            $this->getPublicResolutionStrategy()
        );
    }

    public function protect($filename, $hash)
    {
        $parsedFileID = new ParsedFileID($filename, $hash);
        $protected = $this->getProtectedFilesystem();
        $public = $this->getPublicFilesystem();

        $expectedPublicFileID = $this->getPublicResolutionStrategy()->buildFileID($parsedFileID);

        $this->moveBetweenFileStore(
            $parsedFileID,
            $public,
            $this->getPublicResolutionStrategy(),
            $protected,
            $this->getProtectedResolutionStrategy()
        );
    }

    /**
     * Move a file (and its associative variants) between filesystems
     *
     * @param string $fileID
     * @param Filesystem $from
     * @param Filesystem $to
     */
    protected function moveBetweenFilesystems($fileID, Filesystem $from, Filesystem $to)
    {
        foreach ($this->findVariants($fileID, $from) as $nextID) {
            // Copy via stream
            $stream = $from->readStream($nextID);
            $to->putStream($nextID, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $from->delete($nextID);
        }

        // Truncate empty dirs
        $this->truncateDirectory(dirname($fileID), $from);
    }

    /**
     * Move a file and its associated variant from one file store to another adjusting the file name format.
     * @param ParsedFileID $parsedFileID
     * @param Filesystem $from
     * @param FileResolutionStrategy $fromStrategy
     * @param Filesystem $to
     * @param FileResolutionStrategy $toStrategy
     */
    protected function moveBetweenFileStore(
        ParsedFileID $parsedFileID,
        Filesystem $from,
        FileResolutionStrategy $fromStrategy,
        Filesystem $to,
        FileResolutionStrategy $toStrategy
    ) {
        $idsToDelete = [];

        /** @var ParsedFileID $variantParsedFileID */
        foreach ($fromStrategy->findVariants($parsedFileID, $from) as $variantParsedFileID) {
            // Copy via stream
            $fromFileID = $variantParsedFileID->getFileID();
            $toFileID = $toStrategy->buildFileID($variantParsedFileID);

            $stream = $from->readStream($fromFileID);
            $to->putStream($toFileID, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $idsToDelete[] = $fromFileID;
        }

        foreach ($idsToDelete as $fileID) {
            $from->delete($fileID);
            // Truncate empty dirs
            $this->truncateDirectory(dirname($fileID), $from);
        }
    }

    public function grant($filename, $hash)
    {

        $fileID = $this->getFileID($filename, $hash);

        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(self::GRANTS_SESSION) ?: array();
        $granted[$fileID] = true;
        $session->set(self::GRANTS_SESSION, $granted);
    }

    public function revoke($filename, $hash)
    {
        $fileID = $this->getFileID($filename, $hash);
        if (!$fileID) {
            $fileID = $this->getProtectedResolutionStrategy()->buildFileID(new ParsedFileID($filename, $hash));
        }

        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(self::GRANTS_SESSION) ?: array();
        unset($granted[$fileID]);
        if ($granted) {
            $session->set(self::GRANTS_SESSION, $granted);
        } else {
            $session->clear(self::GRANTS_SESSION);
        }
    }

    public function canView($filename, $hash)
    {
        $canView = $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs, FileResolutionStrategy $strategy, $visibility) {
                if ($visibility === AssetStore::VISIBILITY_PROTECTED) {
                    // Can't return false directly otherwise applyToFileOnFilesystem will keep looking
                    return $this->isGranted($parsedFileID) ?: null;
                }
                return true;
            },
            new ParsedFileID($filename, $hash)
        );
        return $canView === true;
    }

    /**
     * Determine if a grant exists for the given FileID
     *
     * @param string|ParsedFileID $fileID
     * @return bool
     */
    protected function isGranted($fileID)
    {
        // Since permissions are applied to the non-variant only,
        // map back to the original file before checking
        $parsedFileID = $this->getProtectedResolutionStrategy()->stripVariant($fileID);

        // Make sure our File ID got understood
        if ($parsedFileID && $originalID = $parsedFileID->getFileID()) {
            $session = Controller::curr()->getRequest()->getSession();
            $granted = $session->get(self::GRANTS_SESSION) ?: array();
            return !empty($granted[$originalID]);
        }

        // Our file ID didn't make sense
        return false;
    }

    /**
     * get sha1 hash from stream
     *
     * @param resource $stream
     * @return string str1 hash
     */
    protected function getStreamSHA1($stream)
    {
        Util::rewindStream($stream);
        $context = hash_init('sha1');
        hash_update_stream($context, $stream);
        return hash_final($context);
    }

    /**
     * Get stream as a file
     *
     * @param resource $stream
     * @return string Filename of resulting stream content
     * @throws Exception
     */
    protected function getStreamAsFile($stream)
    {
        // Get temporary file and name
        $file = tempnam(sys_get_temp_dir(), 'ssflysystem');
        $buffer = fopen($file, 'w');
        if (!$buffer) {
            throw new Exception("Could not create temporary file");
        }

        // Transfer from given stream
        Util::rewindStream($stream);
        stream_copy_to_stream($stream, $buffer);
        if (!fclose($buffer)) {
            throw new Exception("Could not write stream to temporary file");
        }

        return $file;
    }

    /**
     * Determine if this stream is seekable
     *
     * @param resource $stream
     * @return bool True if this stream is seekable
     */
    protected function isSeekableStream($stream)
    {
        return Util::isSeekableStream($stream);
    }

    /**
     * Invokes the conflict resolution scheme on the given content, and invokes a callback if
     * the storage request is approved.
     *
     * @param callable $callback Will be invoked and passed a fileID if the file should be stored
     * @param string $filename Name for the resulting file
     * @param string $hash SHA1 of the original file content
     * @param string $variant Variant to write
     * @param array $config Write options. {@see AssetStore}
     * @return array Tuple associative array (Filename, Hash, Variant)
     * @throws Exception
     */
    protected function writeWithCallback($callback, $filename, $hash, $variant = null, $config = array())
    {
        // Set default conflict resolution
        $conflictResolution = empty($config['conflict'])
            ? $this->getDefaultConflictResolution($variant)
            : $config['conflict'];


        // Validate parameters
        if ($variant && $conflictResolution === AssetStore::CONFLICT_RENAME) {
            // As variants must follow predictable naming rules, they should not be dynamically renamed
            throw new InvalidArgumentException("Rename cannot be used when writing variants");
        }
        if (!$filename) {
            throw new InvalidArgumentException("Filename is missing");
        }
        if (!$hash) {
            throw new InvalidArgumentException("File hash is missing");
        }

        $parsedFileID = new ParsedFileID($filename, $hash, $variant);

        $fsObjs = $this->applyToFileOnFilesystem(
            function (
                ParsedFileID $noVariantParsedFileID,
                Filesystem $fs,
                FileResolutionStrategy $strategy,
                $visibility
            ) use ($parsedFileID) {
                $parsedFileID = $strategy->generateVariantFileID($parsedFileID, $fs);

                if ($parsedFileID) {
                    return [$parsedFileID, $fs, $strategy, $visibility];
                }

                // Keep looking
                return false;
            },
            $parsedFileID->setVariant('')
        );

        if ($fsObjs) {
            list($parsedFileID, $fs, $strategy, $visibility) = $fsObjs;
        } else {
            if (isset($config['visibility']) && $config['visibility'] === self::VISIBILITY_PUBLIC) {
                $fs = $this->getPublicFilesystem();
                $strategy = $this->getPublicResolutionStrategy();
                $visibility = self::VISIBILITY_PUBLIC;
            } else {
                $fs = $this->getProtectedFilesystem();
                $strategy = $this->getProtectedResolutionStrategy();
                $visibility = self::VISIBILITY_PROTECTED;
            }
        }

        $targetFileID = $strategy->buildFileID($parsedFileID);

        // If overwrite is requested, simply put
        if ($conflictResolution === AssetStore::CONFLICT_OVERWRITE || !$fs->has($targetFileID)) {
            $parsedFileID = $parsedFileID->setFileID($targetFileID);
        } elseif ($conflictResolution === static::CONFLICT_EXCEPTION) {
            throw new InvalidArgumentException("File already exists at path {$targetFileID}");
        } elseif ($conflictResolution === static::CONFLICT_RENAME) {
            foreach ($this->fileGeneratorFor($targetFileID) as $candidate) {
                if (!$fs->has($candidate)) {
                    $parsedFileID = $strategy->parseFileID($candidate)->setHash($hash);
                    break;
                }
            }
        } else {
            // Use exists file
            if (empty($variant)) {
                // If deferring to the existing file, return the sha of the existing file,
                // unless we are writing a variant (which has the same hash value as its original file)
                $hash = $this->getStreamSHA1($fs->readStream($targetFileID));
                $parsedFileID = $parsedFileID->setHash($hash);
            }
            return $parsedFileID->getTuple();
        }

        // Submit and validate result
        $result = $callback($fs, $parsedFileID->getFileID());
        if (!$result) {
            throw new Exception("Could not save {$filename}");
        }

        return $parsedFileID->getTuple();
    }

    /**
     * Choose a default conflict resolution
     *
     * @param string $variant
     * @return string
     */
    protected function getDefaultConflictResolution($variant)
    {
        // If using new naming scheme (segment by hash) it's normally safe to overwrite files.
        // Variants are also normally safe to overwrite, since lazy-generation is implemented at a higher level.
        $legacy = $this->useLegacyFilenames();
        if (!$legacy || $variant) {
            return AssetStore::CONFLICT_OVERWRITE;
        }

        // Legacy behaviour is to rename
        return AssetStore::CONFLICT_RENAME;
    }

    /**
     * Determine if legacy filenames should be used. These do not have hash path parts.
     *
     * @return bool
     */
    protected function useLegacyFilenames()
    {
        return $this->config()->get('legacy_filenames');
    }

    public function getMetadata($filename, $hash, $variant = null)
    {
        // If `applyToFileOnFilesystem` calls our closure we'll know for sure that a file exists
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs) {
                return $fs->getMetadata($parsedFileID->getFileID());
            },
            new ParsedFileID($filename, $hash, $variant)
        );
    }

    public function getMimeType($filename, $hash, $variant = null)
    {
        // If `applyToFileOnFilesystem` calls our closure we'll know for sure that a file exists
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs) {
                return $fs->getMimetype($parsedFileID->getFileID());
            },
            new ParsedFileID($filename, $hash, $variant)
        );
    }

    public function exists($filename, $hash, $variant = null)
    {
        if (empty($filename) || empty($hash)) {
            return false;
        }

        // If `applyToFileOnFilesystem` calls our closure we'll know for sure that a file exists
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs, FileResolutionStrategy $strategy) use ($hash) {
                $parsedFileID = $strategy->stripVariant($parsedFileID);

                if ($parsedFileID && $originalFileID = $parsedFileID->getFileID()) {
                    if ($fs->has($originalFileID)) {
                        $stream = $fs->readStream($originalFileID);
                        $hashFromFile = $this->getStreamSHA1($stream);

                        // If the hash of the file doesn't match we return false, because we want to keep looking.
                        return strpos($hashFromFile, $hash) === 0 ? true : false;
                    }
                }

                return false;
            },
            new ParsedFileID($filename, $hash, $variant)
        ) ?: false;
    }

    /**
     * Determine the path that should be written to, given the conflict resolution scheme
     *
     * @param string $conflictResolution
     * @param string $fileID
     * @return string|false Safe filename to write to. If false, then don't write, and use existing file.
     * @throws Exception
     */
    protected function resolveConflicts($conflictResolution, $fileID)
    {
        // If overwrite is requested, simply put
        if ($conflictResolution === AssetStore::CONFLICT_OVERWRITE) {
            return $fileID;
        }

        // Otherwise, check if this exists
        $exists = $this->applyToFileOnFilesystem(function () {
            return true;
        }, $fileID);
        if (!$exists) {
            return $fileID;
        }

        // Flysystem defaults to use_existing
        switch ($conflictResolution) {
            // Throw tantrum
            case static::CONFLICT_EXCEPTION: {
                throw new InvalidArgumentException("File already exists at path {$fileID}");
            }

            // Rename
            case static::CONFLICT_RENAME: {
                foreach ($this->fileGeneratorFor($fileID) as $candidate) {
                    $exists = $this->applyToFileOnFilesystem(function () {
                        return true;
                    }, $candidate);
                    if (!$exists) {
                        return $candidate;
                    }
                }

                throw new InvalidArgumentException("File could not be renamed with path {$fileID}");
            }

            // Use existing file
            case static::CONFLICT_USE_EXISTING:
            default: {
                return false;
            }
        }
    }

    /**
     * Get an asset renamer for the given filename.
     *
     * @param string $fileID Adapter specific identifier for this file/version
     * @return AssetNameGenerator
     */
    protected function fileGeneratorFor($fileID)
    {
        return Injector::inst()->createWithArgs(AssetNameGenerator::class, array($fileID));
    }

    /**
     * Performs filename cleanup before sending it back.
     *
     * This name should not contain hash or variants.
     *
     * @param string $filename
     * @return string
     * @deprecated 1.4.0
     */
    protected function cleanFilename($filename)
    {
        /** @var FileIDHelper $helper */
        $helper = Injector::inst()->get(HashFileIDHelper::class);
        return $helper->cleanFilename($filename);
    }

    /**
     * Get Filename and Variant from FileID
     *
     * @param string $fileID
     * @return array
     * @deprecated 1.4.0
     */
    protected function parseFileID($fileID)
    {
        /** @var ParsedFileID $parsedFileID */
        $parsedFileID = $this->getProtectedResolutionStrategy()->parseFileID($fileID);
        return $parsedFileID ? $parsedFileID->getTuple() : null;
    }

    /**
     * Given a FileID, map this back to the original filename, trimming variant and hash
     *
     * @param string $fileID Adapter specific identifier for this file/version
     * @return string Filename for this file, omitting hash and variant
     * @deprecated 1.4.0
     */
    protected function getOriginalFilename($fileID)
    {
        $parsedFiledID = $this->getPublicResolutionStrategy()->parseFileID($fileID);
        return $parsedFiledID ? $parsedFiledID->getFilename() : null;
    }

    /**
     * Get variant from this file
     *
     * @param string $fileID
     * @return string
     * @deprecated 1.4.0
     */
    protected function getVariant($fileID)
    {
        $parsedFiledID = $this->getPublicResolutionStrategy()->parseFileID($fileID);
        return $parsedFiledID ? $parsedFiledID->getVariant() : null;
    }

    /**
     * Remove variant from a fileID
     *
     * @param string $fileID
     * @return string FileID without variant
     * @deprecated
     */
    protected function removeVariant($fileID)
    {
        $parsedFiledID = $this->getPublicResolutionStrategy()->parseFileID($fileID);
        if ($parsedFiledID) {
            return $this->getPublicResolutionStrategy()->buildFileID($parsedFiledID->setVariant(''));
        }

        return $fileID;
    }

    /**
     * Map file tuple (hash, name, variant) to a filename to be used by flysystem
     *
     * The resulting file will look something like my/directory/EA775CB4D4/filename__variant.jpg
     *
     * @param string $filename Name of file
     * @param string $hash Hash of original file
     * @param string $variant (if given)
     * @return string Adapter specific identifier for this file/version
     */
    protected function getFileID($filename, $hash, $variant = null)
    {
        $parsedFileID = new ParsedFileID($filename, $hash, $variant);
        $fileID = $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID) {
                return $parsedFileID->getFileID();
            },
            $parsedFileID
        );

        // We couldn't find a file matching the requested critera
        if (!$fileID) {
            // Default to using the file ID format of the protected store
            $fileID = $this->getProtectedResolutionStrategy()->buildFileID($parsedFileID);
        }

        return $fileID;
    }

    /**
     * Ensure each adapter re-generates its own server configuration files
     */
    public static function flush()
    {
        // Ensure that this instance is constructed on flush, thus forcing
        // bootstrapping of necessary .htaccess / web.config files
        $instance = singleton(AssetStore::class);
        if ($instance instanceof FlysystemAssetStore) {
            $public = $instance->getPublicFilesystem();
            if ($public instanceof Filesystem) {
                $publicAdapter = $public->getAdapter();
                if ($publicAdapter instanceof AssetAdapter) {
                    $publicAdapter->flush();
                }
            }
            $protected = $instance->getProtectedFilesystem();
            if ($protected instanceof Filesystem) {
                $protectedAdapter = $protected->getAdapter();
                if ($protectedAdapter instanceof AssetAdapter) {
                    $protectedAdapter->flush();
                }
            }
        }
    }

    public function getResponseFor($asset)
    {
        $public = $this->getPublicFilesystem();
        $protected = $this->getProtectedFilesystem();
        $publicStrategy = $this->getPublicResolutionStrategy();
        $protectedStrategy = $this->getPublicResolutionStrategy();

        // If the file exists on the public store, we just straight return it.
        if ($public->has($asset)) {
            return $this->createResponseFor($public, $asset);
        }

        // If the file exists in the protected store and the user has been explicitely granted access to it
        if ($protected->has($asset) && $this->isGranted($asset)) {
            $parsedFileID = $protectedStrategy->resolveFileID($asset, $protected);
            if ($this->canView($parsedFileID->getFilename(), $parsedFileID->getHash())) {
                return $this->createResponseFor($protected, $asset);
            }
            // Let's not deny if the file is in the protected store, but is not granted.
            // We might be able to redirect to a live version.
        }

        // Check if we can find a URL to redirect to
        if ($parsedFileID = $publicStrategy->softResolveFileID($asset, $public)) {
            return $this->createRedirectResponse($parsedFileID->getFileID());
        }

        // Deny if file is protected and denied
        if ($protected->has($asset)) {
            return $this->createDeniedResponse();
        }

        // We've looked everywhere and couldn't find a file
        return $this->createMissingResponse();
    }

    /**
     * Generate an {@see HTTPResponse} for the given file from the source filesystem
     * @param Filesystem $flysystem
     * @param string $fileID
     * @return HTTPResponse
     */
    protected function createResponseFor(Filesystem $flysystem, $fileID)
    {
        // Block directory access
        if ($flysystem->get($fileID) instanceof Directory) {
            return $this->createDeniedResponse();
        }

        // Create streamable response
        $stream = $flysystem->readStream($fileID);
        $size = $flysystem->getSize($fileID);
        $mime = $flysystem->getMimetype($fileID);
        $response = HTTPStreamResponse::create($stream, $size)
            ->addHeader('Content-Type', $mime);

        // Add standard headers
        $headers = $this->config()->get('file_response_headers');
        foreach ($headers as $header => $value) {
            $response->addHeader($header, $value);
        }
        return $response;
    }

    /**
     * Redirect browser to specified file ID on the public store. Assumes an existence check for the fileID has
     * already occured.
     * @note This was introduced as a patch and will be rewritten/remove in SS4.4.
     * @param $fileID
     * @return HTTPResponse
     */
    private function createRedirectResponse($fileID)
    {
        $response = new HTTPResponse(null, $this->config()->get('redirect_response_code'));
        /** @var PublicAdapter $adapter */
        $adapter = $this->getPublicFilesystem()->getAdapter();
        $response->addHeader('Location', $adapter->getPublicUrl($fileID));
        return $response;
    }

    /**
     * Generate a response for requests to a denied protected file
     *
     * @return HTTPResponse
     */
    protected function createDeniedResponse()
    {
        $code = (int)$this->config()->get('denied_response_code');
        return $this->createErrorResponse($code);
    }

    /**
     * Generate a response for missing file requests
     *
     * @return HTTPResponse
     */
    protected function createMissingResponse()
    {
        $code = (int)$this->config()->get('missing_response_code');
        return $this->createErrorResponse($code);
    }

    /**
     * Create a response with the given error code
     *
     * @param int $code
     * @return HTTPResponse
     */
    protected function createErrorResponse($code)
    {
        $response = new HTTPResponse('', $code);

        // Show message in dev
        if (!Director::isLive()) {
            $response->setBody($response->getStatusDescription());
        }

        return $response;
    }
}

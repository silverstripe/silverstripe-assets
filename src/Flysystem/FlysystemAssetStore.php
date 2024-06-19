<?php

namespace SilverStripe\Assets\Flysystem;

use Exception;
use Generator;
use InvalidArgumentException;
use LogicException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Util;
use SilverStripe\Assets\FilenameParsing\FileIDHelper;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Storage\AssetNameGenerator;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\AssetStoreRouter;
use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * Asset store based on flysystem Filesystem as a backend
 */
class FlysystemAssetStore implements AssetStore, AssetStoreRouter, Flushable
{
    use Configurable;
    use Extensible;

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
     * Define the HTTP Response code for request that should be temporarily redirected to a different URL. Defaults to
     * 302.
     * @config
     * @var int
     */
    private static $redirect_response_code = 302;

    /**
     * Define the HTTP Response code for request that should be permanently redirected to a different URL. Defaults to
     * 301.
     * @config
     * @var int
     */
    private static $permanent_redirect_response_code = 301;

    /**
     * Custom headers to add to all custom file responses
     *
     * @config
     * @var array
     */
    private static $file_response_headers = [
        'Cache-Control' => 'private'
    ];

    /**
     * Assign new flysystem backend
     *
     * @param Filesystem $filesystem
     * @throws InvalidArgumentException
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
     * @throws InvalidArgumentException
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
     * @throws LogicException
     */
    public function getProtectedFilesystem()
    {
        if (!$this->protectedFilesystem) {
            throw new LogicException("Filesystem misconfiguration error");
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
            throw new LogicException("Filesystem misconfiguration error");
        }
        return $this->publicResolutionStrategy;
    }

    /**
     * @param FileResolutionStrategy $publicResolutionStrategy
     */
    public function setPublicResolutionStrategy(FileResolutionStrategy $publicResolutionStrategy)
    {
        $this->publicResolutionStrategy = $publicResolutionStrategy;
    }

    /**
     * @return FileResolutionStrategy
     * @throws LogicException
     */
    public function getProtectedResolutionStrategy()
    {
        if (!$this->protectedResolutionStrategy) {
            $this->protectedResolutionStrategy = Injector::inst()->get(FileResolutionStrategy::class . '.protected');
        }

        if (!$this->protectedResolutionStrategy) {
            throw new LogicException("Filesystem misconfiguration error");
        }
        return $this->protectedResolutionStrategy;
    }

    /**
     * @param FileResolutionStrategy $protectedResolutionStrategy
     */
    public function setProtectedResolutionStrategy(FileResolutionStrategy $protectedResolutionStrategy)
    {
        $this->protectedResolutionStrategy = $protectedResolutionStrategy;
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
     * @param callable $callable Action to apply.
     * @param string|array|ParsedFileID $fileID File identication. Can be a string, a file tuple or a ParsedFileID
     * @param bool $strictHashCheck
     * @return mixed
     */
    protected function applyToFileOnFilesystem(callable $callable, ParsedFileID $parsedFileID, $strictHashCheck = true)
    {
        $publicSet = [
            $this->getPublicFilesystem(),
            $this->getPublicResolutionStrategy(),
            FlysystemAssetStore::VISIBILITY_PUBLIC
        ];

        $protectedSet = [
            $this->getProtectedFilesystem(),
            $this->getProtectedResolutionStrategy(),
            FlysystemAssetStore::VISIBILITY_PROTECTED
        ];

        $hasher = Injector::inst()->get(FileHashingService::class);

        /** @var Filesystem $fs */
        /** @var FileResolutionStrategy $strategy */
        /** @var string $visibility */

        // First we try to search for exact file id string match
        foreach ([$publicSet, $protectedSet] as $set) {
            list($fs, $strategy, $visibility) = $set;

            // Get a FileID string based on the type of FileID
            $fileID =  $strategy->buildFileID($parsedFileID);

            if ($fs->has($fileID)) {
                // Let's try validating the hash of our file
                if ($parsedFileID->getHash()) {
                    $mainFileID = $strategy->buildFileID($strategy->stripVariant($parsedFileID));

                    if (!$fs->has($mainFileID)) {
                        // The main file doesn't exists ... this is kind of weird.
                        continue;
                    }

                    $actualHash = $hasher->computeFromFile($mainFileID, $fs);
                    if (!$hasher->compare($actualHash, $parsedFileID->getHash())) {
                        continue;
                    }
                }

                // We already have a ParsedFileID, we just need to set the matching file ID string
                $closesureParsedFileID = $parsedFileID->setFileID($fileID);

                $response = $callable(
                    $closesureParsedFileID,
                    $fs,
                    $strategy,
                    $visibility
                );
                if ($response !== false) {
                    return $response;
                }
            }
        }

        // Let's fall back to using our FileResolution strategy to see if our FileID matches alternative formats
        foreach ([$publicSet, $protectedSet] as $set) {
            list($fs, $strategy, $visibility) = $set;

            $closesureParsedFileID = $strategy->searchForTuple($parsedFileID, $fs, $strictHashCheck);

            if ($closesureParsedFileID) {
                $response = $callable($closesureParsedFileID, $fs, $strategy, $visibility);
                if ($response !== false) {
                    return $response;
                }
            }
        }

        return null;
    }

    /**
     * Equivalent to `applyToFileOnFilesystem`, only it expects a `fileID1 string instead of a ParsedFileID.
     *
     * @param callable $callable Action to apply.
     * @param string $fileID
     * @param bool $strictHashCheck
     * @return mixed
     */
    protected function applyToFileIDOnFilesystem(callable $callable, $fileID, $strictHashCheck = true)
    {
        $publicSet = [
            $this->getPublicFilesystem(),
            $this->getPublicResolutionStrategy(),
            FlysystemAssetStore::VISIBILITY_PUBLIC
        ];

        $protectedSet = [
            $this->getProtectedFilesystem(),
            $this->getProtectedResolutionStrategy(),
            FlysystemAssetStore::VISIBILITY_PROTECTED
        ];

        /** @var Filesystem $fs */
        /** @var FileResolutionStrategy $strategy */
        /** @var string $visibility */

        // First we try to search for exact file id string match
        foreach ([$publicSet, $protectedSet] as $set) {
            list($fs, $strategy, $visibility) = $set;

            if ($fs->has($fileID)) {
                $parsedFileID = $strategy->resolveFileID($fileID, $fs);
                if ($parsedFileID) {
                    $response = $callable(
                        $parsedFileID,
                        $fs,
                        $strategy,
                        $visibility
                    );
                    if ($response !== false) {
                        return $response;
                    }
                }
            }
        }

        // Let's fall back to using our FileResolution strategy to see if our FileID matches alternative formats
        foreach ([$publicSet, $protectedSet] as $set) {
            list($fs, $strategy, $visibility) = $set;

            $parsedFileID = $strategy->resolveFileID($fileID, $fs);

            if ($parsedFileID) {
                $response = $callable($parsedFileID, $fs, $strategy, $visibility);
                if ($response !== false) {
                    return $response;
                }
            }
        }

        return null;
    }

    public function getCapabilities()
    {
        return [
            'visibility' => [
                FlysystemAssetStore::VISIBILITY_PUBLIC,
                FlysystemAssetStore::VISIBILITY_PROTECTED
            ],
            'conflict' => [
                FlysystemAssetStore::CONFLICT_EXCEPTION,
                FlysystemAssetStore::CONFLICT_OVERWRITE,
                FlysystemAssetStore::CONFLICT_RENAME,
                FlysystemAssetStore::CONFLICT_USE_EXISTING
            ]
        ];
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

    public function setFromLocalFile($path, $filename = null, $hash = null, $variant = null, $config = [])
    {
        // Validate this file exists
        if (!file_exists($path ?? '')) {
            throw new InvalidArgumentException("$path does not exist");
        }

        // Get filename to save to
        if (empty($filename)) {
            $filename = basename($path ?? '');
        }

        $stream = fopen($path ?? '', 'r');
        if ($stream === false) {
            throw new InvalidArgumentException("$path could not be opened for reading");
        }

        try {
            return $this->setFromStream($stream, $filename, $hash, $variant, $config);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function setFromString($data, $filename, $hash = null, $variant = null, $config = [])
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $data ?? '');
        rewind($stream);
        try {
            return $this->setFromStream($stream, $filename, $hash, $variant, $config);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function setFromStream($stream, $filename, $hash = null, $variant = null, $config = [])
    {
        if (empty($filename)) {
            throw new InvalidArgumentException('$filename can not be empty');
        }

        // If the stream isn't rewindable, write to a temporary filename
        if (!$this->isSeekableStream($stream)) {
            $path = $this->getStreamAsFile($stream);
            $result = $this->setFromLocalFile($path, $filename, $hash, $variant, $config);
            unlink($path ?? '');
            return $result;
        }

        $hasher = Injector::inst()->get(FileHashingService::class);

        // When saving original filename, generate hash
        if (!$hash && !$variant) {
            $hash = $hasher->computeFromStream($stream);
        }

        // Callback for saving content
        $callback = function (Filesystem $filesystem, $fileID) use ($stream, $hasher, $hash, $variant) {

            // If there's already a file where we want to write and that file has the same sha1 hash as our source file
            // We just let the existing file sit there pretend to have writen it. This avoid a weird edge case where
            // We try to move an existing file to its own location which causes us to override the file with zero bytes
            if ($filesystem->has($fileID)) {
                $newHash = $hasher->computeFromStream($stream);
                $oldHash = $hasher->computeFromFile($fileID, $filesystem);
                if ($newHash === $oldHash) {
                    return true;
                }
            }

            $result = $filesystem->writeStream($fileID, $stream);

            // If we have an hash for a main file, let's pre-warm our file hashing cache.
            if ($hash || !$variant) {
                $hasher->set($fileID, $filesystem, $hash);
            }

            return $result;
        };

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

                    $hasher = Injector::inst()->get(FileHashingService::class);

                    if ($origin !== $destination) {
                        if ($fs->has($destination)) {
                            $fs->delete($origin);
                            // Invalidate hash of delete file
                            $hasher->invalidate($origin, $fs);
                        } else {
                            $fs->move($origin, $destination);
                            // Move cached hash value to new location
                            $hasher->move($origin, $fs, $destination);
                        }
                        $this->truncateDirectory(dirname($origin ?? ''), $fs);
                    }
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
                    if ($fromFileID !== $toFileID) {
                        if (!$fs->has($toFileID)) {
                            $fs->copy($fromFileID, $toFileID);

                            // Set hash value for new file
                            $hasher = Injector::inst()->get(FileHashingService::class);
                            if ($hash = $hasher->get($fromFileID, $fs)) {
                                $hasher->set($toFileID, $fs, $hash);
                            }
                        }
                    }
                }

                return $pfid->setFilename($newName);
            },
            new ParsedFileID($filename, $hash)
        );

        return $newParsedFiledID ? $newParsedFiledID->getFilename(): null;
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
        $hasher = Injector::inst()->get(FileHashingService::class);

        $deleted = false;
        /** @var ParsedFileID $parsedFileIDToDel */
        foreach ($strategy->findVariants($parsedFileID, $fs) as $parsedFileIDToDel) {
            $fs->delete($parsedFileIDToDel->getFileID());
            $deleted = true;
            $hasher->invalidate($parsedFileIDToDel->getFileID(), $fs);
        }

        // Truncate empty dirs
        $this->truncateDirectory(dirname($parsedFileID->getFileID() ?? ''), $fs);

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
            && ltrim($dirname ?? '', '.')
            && !$this->config()->get('keep_empty_dirs')
            && !$filesystem->listContents($dirname)->toArray()
        ) {
            $filesystem->deleteDirectory($dirname);
            $this->truncateDirectory(dirname($dirname ?? ''), $filesystem);
        }
    }

    public function publish($filename, $hash)
    {
        if ($this->getVisibility($filename, $hash) === AssetStore::VISIBILITY_PUBLIC) {
            // The file is already publish
            return;
        }

        $parsedFileID = new ParsedFileID($filename, $hash);
        $protected = $this->getProtectedFilesystem();
        $public = $this->getPublicFilesystem();

        $this->moveBetweenFileStore(
            $parsedFileID,
            $protected,
            $this->getProtectedResolutionStrategy(),
            $public,
            $this->getPublicResolutionStrategy()
        );
    }

    /**
     * Similar to publish, only any existing files that would be overriden by publishing will be moved back to the
     * protected store.
     * @param $filename
     * @param $hash
     */
    public function swapPublish($filename, $hash)
    {
        if ($this->getVisibility($filename, $hash) === AssetStore::VISIBILITY_PUBLIC) {
            // The file is already publish
            return;
        }

        $hasher = Injector::inst()->get(FileHashingService::class);

        $parsedFileID = new ParsedFileID($filename, $hash);
        $from = $this->getProtectedFilesystem();
        $to = $this->getPublicFilesystem();
        $fromStrategy = $this->getProtectedResolutionStrategy();
        $toStrategy = $this->getPublicResolutionStrategy();
        // Contain a list of temporary file that needs to be move to the $from store once we are done.


        // Look for files that might be overriden by publishing to destination store, those need to be stashed away
        $swapFileIDStr = $toStrategy->buildFileID($parsedFileID);
        $swapFiles = [];
        if ($to->has($swapFileIDStr)) {
            $swapParsedFileID = $toStrategy->resolveFileID($swapFileIDStr, $to);
            foreach ($toStrategy->findVariants($swapParsedFileID, $to) as $variantParsedFileID) {
                $toFileID = $variantParsedFileID->getFileID();
                $fromFileID = $fromStrategy->buildFileID($variantParsedFileID);

                // Cache destination file into the origin store under a `.swap` directory
                $stream = $to->readStream($toFileID);
                $from->writeStream('.swap/' . $fromFileID, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $swapFiles[] = $variantParsedFileID->setFileID($fromFileID);

                // Blast existing variants from the destination
                $to->delete($toFileID);
                $hasher->move($toFileID, $to, '.swap/' . $fromFileID, $from);
                $this->truncateDirectory(dirname($toFileID ?? ''), $to);
            }
        }


        // Let's find all the variants on the origin store ... those need to be moved to the destination
        foreach ($fromStrategy->findVariants($parsedFileID, $from) as $variantParsedFileID) {
            // Copy via stream
            $fromFileID = $variantParsedFileID->getFileID();
            $toFileID = $toStrategy->buildFileID($variantParsedFileID);

            $stream = $from->readStream($fromFileID);
            $to->writeStream($toFileID, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // Remove the origin file and keep the file ID
            $from->delete($fromFileID);
            $hasher->move($fromFileID, $from, $toFileID, $to);
            $this->truncateDirectory(dirname($fromFileID ?? ''), $from);
        }

        foreach ($swapFiles as $variantParsedFileID) {
            $fileID = $variantParsedFileID->getFileID();
            $from->move('.swap/' . $fileID, $fileID);
            $hasher->move('.swap/' . $fileID, $from, $fileID);
        }
        $from->deleteDirectory('.swap');
    }

    public function protect($filename, $hash)
    {
        if ($this->getVisibility($filename, $hash) === AssetStore::VISIBILITY_PROTECTED) {
            // The file is already protected
            return;
        }

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
        FileResolutionStrategy $toStrategy,
        $swap = false
    ) {
        $hasher = Injector::inst()->get(FileHashingService::class);

        // Let's find all the variants on the origin store ... those need to be moved to the destination
        foreach ($fromStrategy->findVariants($parsedFileID, $from) as $variantParsedFileID) {
            // Copy via stream
            $fromFileID = $variantParsedFileID->getFileID();
            $toFileID = $toStrategy->buildFileID($variantParsedFileID);

            $stream = $from->readStream($fromFileID);
            $to->writeStream($toFileID, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // Remove the origin file and keep the file ID
            $idsToDelete[] = $fromFileID;
            $from->delete($fromFileID);

            $hasher->move($fromFileID, $from, $toFileID, $to);
            $this->truncateDirectory(dirname($fromFileID ?? ''), $from);
        }
    }

    public function grant($filename, $hash)
    {

        $fileID = $this->getFileID($filename, $hash);

        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION) ?: [];
        $granted[$fileID] = true;
        $session->set(FlysystemAssetStore::GRANTS_SESSION, $granted);
    }

    public function revoke($filename, $hash)
    {
        $fileID = $this->getFileID($filename, $hash);
        if (!$fileID) {
            $fileID = $this->getProtectedResolutionStrategy()->buildFileID(new ParsedFileID($filename, $hash));
        }

        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION) ?: [];
        unset($granted[$fileID]);
        if ($granted) {
            $session->set(FlysystemAssetStore::GRANTS_SESSION, $granted);
        } else {
            $session->clear(FlysystemAssetStore::GRANTS_SESSION);
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
            $granted = $session->get(FlysystemAssetStore::GRANTS_SESSION) ?: [];
            if (!empty($granted[$originalID])) {
                return true;
            }
            if ($member = Security::getCurrentUser()) {
                $params = ['FileFilename' => $parsedFileID->getFilename()];
                if (File::singleton()->hasExtension(Versioned::class)) {
                    $file = Versioned::withVersionedMode(function () use ($params) {
                        Versioned::set_stage(Versioned::DRAFT);
                        return File::get()->filter($params)->first();
                    });
                } else {
                    $file = File::get()->filter($params)->first();
                }
                if ($file) {
                    return (bool) $file->canView($member);
                }
            }
            return false;
        }

        // Our file ID didn't make sense
        return false;
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
        $buffer = fopen($file ?? '', 'w');
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
     * @throws InvalidArgumentException
     */
    protected function writeWithCallback($callback, $filename, $hash, $variant = null, $config = [])
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
            $targetFileID = $parsedFileID->getFileID();
        } else {
            if (isset($config['visibility']) && $config['visibility'] === FlysystemAssetStore::VISIBILITY_PUBLIC) {
                $fs = $this->getPublicFilesystem();
                $strategy = $this->getPublicResolutionStrategy();
                $visibility = FlysystemAssetStore::VISIBILITY_PUBLIC;
            } else {
                $fs = $this->getProtectedFilesystem();
                $strategy = $this->getProtectedResolutionStrategy();
                $visibility = FlysystemAssetStore::VISIBILITY_PROTECTED;
            }
            $targetFileID = $strategy->buildFileID($parsedFileID);
        }

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
                $hasher = Injector::inst()->get(FileHashingService::class);
                $hash = $hasher->computeFromFile($targetFileID, $fs);
                $parsedFileID = $parsedFileID->setHash($hash);
            }
            return $parsedFileID->getTuple();
        }

        // Submit and validate result
        $callback($fs, $parsedFileID->getFileID());

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
        return AssetStore::CONFLICT_OVERWRITE;
    }

    public function getMetadata($filename, $hash, $variant = null)
    {
        // If `applyToFileOnFilesystem` calls our closure we'll know for sure that a file exists
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs) {
                $path = $parsedFileID->getFileID();
                return [
                    'timestamp' => $fs->lastModified($path),
                    'fileExists' => $fs->fileExists($path),
                    'directoryExists' => $fs->directoryExists($path),
                    'type' => $fs->mimeType($path),
                    'size' => $fs->fileSize($path),
                    'visibility' => $fs->visibility($path),
                ];
            },
            new ParsedFileID($filename, $hash, $variant)
        );
    }

    public function getMimeType($filename, $hash, $variant = null)
    {
        // If `applyToFileOnFilesystem` calls our closure we'll know for sure that a file exists
        return $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs) {
                return $fs->mimetype($parsedFileID->getFileID());
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
                        $hasher = Injector::inst()->get(FileHashingService::class);
                        $actualHash = $hasher->computeFromFile($originalFileID, $fs);

                        // If the hash of the file doesn't match we return false, because we want to keep looking.
                        return $hasher->compare($actualHash, $hash);
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
     * @throws InvalidArgumentException
     */
    protected function resolveConflicts($conflictResolution, $fileID)
    {
        // If overwrite is requested, simply put
        if ($conflictResolution === AssetStore::CONFLICT_OVERWRITE) {
            return $fileID;
        }

        // Otherwise, check if this exists
        $exists = $this->applyToFileIDOnFilesystem(function () {
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
                    $exists = $this->applyToFileIDOnFilesystem(function () {
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
        return Injector::inst()->createWithArgs(AssetNameGenerator::class, [$fileID]);
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
        /** @var HTTPResponse $response */
        /** @var array $context */
        [$response, $context] = $this->generateResponseFor($asset);

        // Give a chance to extensions to tweak the response
        $this->extend('updateResponse', $response, $asset, $context);

        return $response;
    }

    /**
     * Build a response for getResponseFor along with some context information for the `updateResponse` hook.
     * @param string $asset
     * @return array HTTPResponse and some surronding context
     */
    private function generateResponseFor(string $asset): array
    {
        $public = $this->getPublicFilesystem();
        $protected = $this->getProtectedFilesystem();
        $publicStrategy = $this->getPublicResolutionStrategy();
        $protectedStrategy = $this->getPublicResolutionStrategy();

        // If the file exists on the public store, we just straight return it.
        if ($public->has($asset)) {
            return [
                $this->createResponseFor($public, $asset),
                ['visibility' => FlysystemAssetStore::VISIBILITY_PUBLIC]
            ];
        }

        // If the file exists in the protected store and the user has been explicitely granted access to it
        if ($protected->has($asset) && $this->isGranted($asset)) {
            $parsedFileID = $protectedStrategy->resolveFileID($asset, $protected);
            if ($this->canView($parsedFileID->getFilename(), $parsedFileID->getHash())) {
                return [
                    $this->createResponseFor($protected, $asset),
                    ['visibility' => FlysystemAssetStore::VISIBILITY_PROTECTED, 'parsedFileID' => $parsedFileID]
                ];
            }
            // Let's not deny if the file is in the protected store, but is not granted.
            // We might be able to redirect to a live version.
        }

        // Check if we can find a URL to redirect to
        if ($parsedFileID = $publicStrategy->softResolveFileID($asset, $public)) {
            $redirectFileID = $parsedFileID->getFileID();
            $permanentFileID = $publicStrategy->buildFileID($parsedFileID);
            // If our redirect FileID is equal to the permanent file ID, this URL will never change
            $code = $redirectFileID === $permanentFileID ?
                $this->config()->get('permanent_redirect_response_code') :
                $this->config()->get('redirect_response_code');

            return [
                $this->createRedirectResponse($redirectFileID, $code),
                ['visibility' => FlysystemAssetStore::VISIBILITY_PUBLIC, 'parsedFileID' => $parsedFileID]
            ];
        }

        // Deny if file is protected and denied
        if ($protected->has($asset)) {
            return [
                $this->createDeniedResponse(),
                ['visibility' => FlysystemAssetStore::VISIBILITY_PROTECTED]
            ];
        }

        // We've looked everywhere and couldn't find a file
        return [$this->createMissingResponse(), []];
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
        if ($flysystem->directoryExists($fileID)) {
            return $this->createDeniedResponse();
        }

        // Create streamable response
        $stream = $flysystem->readStream($fileID);
        $size = $flysystem->fileSize($fileID);
        $mime = $flysystem->mimetype($fileID);
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
     * @param string $fileID
     * @param int $code
     * @return HTTPResponse
     */
    private function createRedirectResponse($fileID, $code)
    {
        $response = new HTTPResponse(null, $code);
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

    public function normalisePath($fileID)
    {
        return $this->applyToFileIDOnFilesystem(
            function (...$args) {
                return $this->normaliseToDefaultPath(...$args);
            },
            $fileID
        );
    }

    public function normalise($filename, $hash)
    {
        return $this->applyToFileOnFilesystem(
            function (...$args) {
                return $this->normaliseToDefaultPath(...$args);
            },
            new ParsedFileID($filename, $hash)
        );
    }

    /**
     * Given a parsed file ID, move the matching file and all its variants to the default position as defined by the
     * provided strategy.
     * @param ParsedFileID $pfid
     * @param Filesystem $fs
     * @param FileResolutionStrategy $strategy
     * @return array List of new file names with the old name as the key
     * @throws \League\Flysystem\FileExistsException
     * @throws \League\Flysystem\UnableToCheckExistence
     */
    private function normaliseToDefaultPath(ParsedFileID $pfid, Filesystem $fs, FileResolutionStrategy $strategy)
    {
        $ops = [];
        $hasher = Injector::inst()->get(FileHashingService::class);

        // Let's make sure we are using a valid file name
        $cleanFilename = $strategy->cleanFilename($pfid->getFilename());
        // Check if our cleaned filename is different from the original filename
        if ($cleanFilename !== $pfid->getFilename()) {
            // We need to build a new filename that doesn't conflict with any existing file
            $fileID = $strategy->buildFileID($pfid->setVariant('')->setFilename($cleanFilename));
            if ($fs->has($fileID)) {
                foreach ($this->fileGeneratorFor($fileID) as $candidate) {
                    if (!$fs->has($candidate)) {
                        $cleanFilename = $strategy->parseFileID($candidate)->getFilename();
                        break;
                    }
                }
            }
        }

        // Let's move all the variants
        foreach ($strategy->findVariants($pfid, $fs) as $variantPfid) {
            $origin = $variantPfid->getFileID();
            $targetVariantFileID = $strategy->buildFileID($variantPfid->setFilename($cleanFilename));
            if ($targetVariantFileID !== $origin) {
                if ($fs->has($targetVariantFileID)) {
                    $fs->delete($origin);
                    $hasher->invalidate($origin, $fs);
                } else {
                    $fs->move($origin, $targetVariantFileID);
                    $hasher->move($origin, $fs, $targetVariantFileID);
                    $ops[$origin] = $targetVariantFileID;
                }
                $this->truncateDirectory(dirname($origin ?? ''), $fs);
            }
        }

        // Our strategy will have cleaned up the name
        $pfid = $pfid->setFilename($cleanFilename);

        return array_merge($pfid->getTuple(), ['Operations' => $ops]);
    }
}

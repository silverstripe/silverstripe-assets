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
     * Enable to use legacy filename behaviour (omits hash)
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
     * Return the store that contains the given fileID
     *
     * @param string $fileID Internal file identifier
     * @return Filesystem
     */
    protected function getFilesystemFor($fileID)
    {
        if ($this->getPublicFilesystem()->has($fileID)) {
            return $this->getPublicFilesystem();
        }

        if ($this->getProtectedFilesystem()->has($fileID)) {
            return $this->getProtectedFilesystem();
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
        $fileID = $this->getFileID($filename, $hash);
        if ($this->getPublicFilesystem()->has($fileID)) {
            return self::VISIBILITY_PUBLIC;
        }

        if ($this->getProtectedFilesystem()->has($fileID)) {
            return self::VISIBILITY_PROTECTED;
        }

        return null;
    }


    public function getAsStream($filename, $hash, $variant = null)
    {
        $fileID = $this->getFileID($filename, $hash, $variant);
        return $this
            ->getFilesystemFor($fileID)
            ->readStream($fileID);
    }

    public function getAsString($filename, $hash, $variant = null)
    {
        $fileID = $this->getFileID($filename, $hash, $variant);
        return $this
            ->getFilesystemFor($fileID)
            ->read($fileID);
    }

    public function getAsURL($filename, $hash, $variant = null, $grant = true)
    {
        $fileID = $this->getFileID($filename, $hash, $variant);

        // Check with filesystem this asset exists in
        $public = $this->getPublicFilesystem();
        $protected = $this->getProtectedFilesystem();
        if ($public->has($fileID) || !$protected->has($fileID)) {
            /** @var PublicAdapter $publicAdapter */
            $publicAdapter = $public->getAdapter();
            return $publicAdapter->getPublicUrl($fileID);
        }

        if ($grant) {
            $this->grant($filename, $hash);
        }

        /** @var ProtectedAdapter $protectedAdapter */
        $protectedAdapter = $protected->getAdapter();
        return $protectedAdapter->getProtectedUrl($fileID);
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
        $callback = function (Filesystem $filesystem, $fileID) use ($path) {
            // Read contents as string into flysystem
            $handle = fopen($path, 'r');
            if ($handle === false) {
                throw new InvalidArgumentException("$path could not be opened for reading");
            }
            $result = $filesystem->putStream($fileID, $handle);
            if (is_resource($handle)) {
                fclose($handle);
            }
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
        if (!$variant) {
            $hash = $this->getStreamSHA1($stream);
        }

        // Submit to conflict check
        return $this->writeWithCallback($callback, $filename, $hash, $variant, $config);
    }

    public function delete($filename, $hash)
    {
        $fileID = $this->getFileID($filename, $hash);
        $protected = $this->deleteFromFilesystem($fileID, $this->getProtectedFilesystem());
        $public = $this->deleteFromFilesystem($fileID, $this->getPublicFilesystem());
        return $protected || $public;
    }

    public function rename($filename, $hash, $newName)
    {
        if (empty($newName)) {
            throw new InvalidArgumentException("Cannot write to empty filename");
        }
        if ($newName === $filename) {
            return $filename;
        }
        $newName = $this->cleanFilename($newName);
        $fileID = $this->getFileID($filename, $hash);
        $filesystem = $this->getFilesystemFor($fileID);
        foreach ($this->findVariants($fileID, $filesystem) as $nextID) {
            // Get variant and build new ID for this variant
            $variant = $this->getVariant($nextID);
            $newID = $this->getFileID($newName, $hash, $variant);
            $filesystem->rename($nextID, $newID);
        }
        // Truncate empty dirs
        $this->truncateDirectory(dirname($fileID), $filesystem);
        return $newName;
    }

    public function copy($filename, $hash, $newName)
    {
        if (empty($newName)) {
            throw new InvalidArgumentException("Cannot write to empty filename");
        }
        if ($newName === $filename) {
            return $filename;
        }
        $newName = $this->cleanFilename($newName);
        $fileID = $this->getFileID($filename, $hash);
        $filesystem = $this->getFilesystemFor($fileID);
        foreach ($this->findVariants($fileID, $filesystem) as $nextID) {
            // Get variant and build new ID for this variant
            $variant = $this->getVariant($nextID);
            $newID = $this->getFileID($newName, $hash, $variant);
            $filesystem->copy($nextID, $newID);
        }
        return $newName;
    }

    /**
     * Delete the given file (and any variants) in the given {@see Filesystem}
     *
     * @param string $fileID
     * @param Filesystem $filesystem
     * @return bool True if a file was deleted
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
        $fileID = $this->getFileID($filename, $hash);
        $protected = $this->getProtectedFilesystem();
        $public = $this->getPublicFilesystem();
        $this->moveBetweenFilesystems($fileID, $protected, $public);
    }

    public function protect($filename, $hash)
    {
        $fileID = $this->getFileID($filename, $hash);
        $public = $this->getPublicFilesystem();
        $protected = $this->getProtectedFilesystem();
        $this->moveBetweenFilesystems($fileID, $public, $protected);
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

    public function grant($filename, $hash)
    {
        $session = Controller::curr()->getRequest()->getSession();
        $fileID = $this->getFileID($filename, $hash);
        $granted = $session->get(self::GRANTS_SESSION) ?: array();
        $granted[$fileID] = true;
        $session->set(self::GRANTS_SESSION, $granted);
    }

    public function revoke($filename, $hash)
    {
        $fileID = $this->getFileID($filename, $hash);
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
        $fileID = $this->getFileID($filename, $hash);
        if ($this->getProtectedFilesystem()->has($fileID)) {
            return $this->isGranted($fileID);
        }
        return true;
    }

    /**
     * Determine if a grant exists for the given FileID
     *
     * @param string $fileID
     * @return bool
     */
    protected function isGranted($fileID)
    {
        // Since permissions are applied to the non-variant only,
        // map back to the original file before checking
        $originalID = $this->removeVariant($fileID);
        $session = Controller::curr()->getRequest()->getSession();
        $granted = $session->get(self::GRANTS_SESSION) ?: array();
        return !empty($granted[$originalID]);
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
        if (empty($config['conflict'])) {
            $conflictResolution = $this->getDefaultConflictResolution($variant);
        } else {
            $conflictResolution = $config['conflict'];
        }

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

        $filename = $this->cleanFilename($filename);
        $fileID = $this->getFileID($filename, $hash, $variant);

        // Check conflict resolution scheme
        $resolvedID = $this->resolveConflicts($conflictResolution, $fileID);
        if ($resolvedID !== false) {
            // Check if source file already exists on the filesystem
            $mainID = $this->getFileID($filename, $hash);
            $filesystem = $this->getFilesystemFor($mainID);

            // If writing a new file use the correct visibility
            if (!$filesystem) {
                // Default to public store unless requesting protected store
                if (isset($config['visibility']) && $config['visibility'] === self::VISIBILITY_PROTECTED) {
                    $filesystem = $this->getProtectedFilesystem();
                } else {
                    $filesystem = $this->getPublicFilesystem();
                }
            }

            // Submit and validate result
            $result = $callback($filesystem, $resolvedID);
            if (!$result) {
                throw new Exception("Could not save {$filename}");
            }

            // in case conflict resolution renamed the file, return the renamed
            $filename = $this->getOriginalFilename($resolvedID);
        } elseif (empty($variant)) {
            // If deferring to the existing file, return the sha of the existing file,
            // unless we are writing a variant (which has the same hash value as its original file)
            $stream = $this
                ->getFilesystemFor($fileID)
                ->readStream($fileID);
            $hash = $this->getStreamSHA1($stream);
        }

        return array(
            'Filename' => $filename,
            'Hash' => $hash,
            'Variant' => $variant
        );
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
        $fileID = $this->getFileID($filename, $hash, $variant);
        $filesystem = $this->getFilesystemFor($fileID);
        if ($filesystem) {
            return $filesystem->getMetadata($fileID);
        }
        return null;
    }

    public function getMimeType($filename, $hash, $variant = null)
    {
        $fileID = $this->getFileID($filename, $hash, $variant);
        $filesystem = $this->getFilesystemFor($fileID);
        if ($filesystem) {
            return $filesystem->getMimetype($fileID);
        }
        return null;
    }

    public function exists($filename, $hash, $variant = null)
    {
        $fileID = $this->getFileID($filename, $hash, $variant);
        $filesystem = $this->getFilesystemFor($fileID);
        return !empty($filesystem);
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
        $exists = $this->getFilesystemFor($fileID);
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
                    if (!$this->getFilesystemFor($candidate)) {
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
     */
    protected function cleanFilename($filename)
    {
        // Since we use double underscore to delimit variants, eradicate them from filename
        return preg_replace('/_{2,}/', '_', $filename);
    }

    /**
     * Get Filename and Variant from fileid
     *
     * @param string $fileID
     * @return array
     */
    protected function parseFileID($fileID)
    {
        if ($this->useLegacyFilenames()) {
            $pattern = '#^(?<folder>([^/]+/)*)(?<basename>((?<!__)[^/.])+)(__(?<variant>[^.]+))?(?<extension>(\..+)*)$#';
        } else {
            $pattern = '#^(?<folder>([^/]+/)*)(?<hash>[a-zA-Z0-9]{10})/(?<basename>((?<!__)[^/.])+)(__(?<variant>[^.]+))?(?<extension>(\..+)*)$#';
        }

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern, $fileID, $matches)) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        $variant = isset($matches['variant']) ? $matches['variant'] : null;
        $hash = isset($matches['hash']) ? $matches['hash'] : null;
        return [
            'Filename' => $filename,
            'Variant' => $variant,
            'Hash' => $hash
        ];
    }

    /**
     * Try to parse a file ID using the old SilverStripe 3 format legacy or the SS4 legacy filename format.
     *
     * @param string $fileID
     * @return array
     */
    private function parseLegacyFileID($fileID)
    {
        // assets/folder/_resampled/ResizedImageWzEwMCwxMzNd/basename.extension
        $ss3Pattern = '#^(?<folder>([^/]+/)*?)(_resampled/(?<variant>([^/.]+))/)?((?<basename>((?<!__)[^/.])+))(?<extension>(\..+)*)$#';
        // assets/folder/basename__ResizedImageWzEwMCwxMzNd.extension
        $ss4LegacyPattern = '#^(?<folder>([^/]+/)*)(?<basename>((?<!__)[^/.])+)(__(?<variant>[^.]+))?(?<extension>(\..+)*)$#';

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($ss3Pattern, $fileID, $matches) && !preg_match($ss4LegacyPattern, $fileID, $matches)) {
            return null;
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        $variant = isset($matches['variant']) ? $matches['variant'] : null;
        return [
            'Filename' => $filename,
            'Variant' => $variant
        ];
    }

    /**
     * Given a FileID, map this back to the original filename, trimming variant and hash
     *
     * @param string $fileID Adapter specific identifier for this file/version
     * @return string Filename for this file, omitting hash and variant
     */
    protected function getOriginalFilename($fileID)
    {
        $parts = $this->parseFileID($fileID);
        if (!$parts) {
            return null;
        }
        return $parts['Filename'];
    }

    /**
     * Get variant from this file
     *
     * @param string $fileID
     * @return string
     */
    protected function getVariant($fileID)
    {
        $parts = $this->parseFileID($fileID);
        if (!$parts) {
            return null;
        }
        return $parts['Variant'];
    }

    /**
     * Remove variant from a fileID
     *
     * @param string $fileID
     * @return string FileID without variant
     */
    protected function removeVariant($fileID)
    {
        $variant = $this->getVariant($fileID);
        if (empty($variant)) {
            return $fileID;
        }
        return str_replace("__{$variant}", '', $fileID);
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
        // Since we use double underscore to delimit variants, eradicate them from filename
        $filename = $this->cleanFilename($filename);
        $name = basename($filename);

        // Split extension
        $extension = null;
        if (($pos = strpos($name, '.')) !== false) {
            $extension = substr($name, $pos);
            $name = substr($name, 0, $pos);
        }

        // Unless in legacy mode, inject hash just prior to the filename
        if ($this->useLegacyFilenames()) {
            $fileID = $name;
        } else {
            $fileID = substr($hash, 0, 10) . '/' . $name;
        }

        // Add directory
        $dirname = ltrim(dirname($filename), '.');
        if ($dirname) {
            $fileID = $dirname . '/' . $fileID;
        }

        // Add variant
        if ($variant) {
            $fileID .= '__' . $variant;
        }

        // Add extension
        if ($extension) {
            $fileID .= $extension;
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

        // If the file exists on the public store, we just straight return it.
        if ($public->has($asset)) {
            return $this->createResponseFor($public, $asset);
        }

        // If the file exists in the protected store and the user has been explicitely granted access to it
        if ($protected->has($asset) && $this->isGranted($asset)) {
            return $this->createResponseFor($protected, $asset);
            // Let's not deny if the file is in the protected store, but is not granted.
            // We might be able to redirect to a live version.
        }

        // If we found a URL to redirect to
        if ($redirectUrl = $this->searchForEquivalentFileID($asset)) {
            if ($redirectUrl != $asset && $public->has($redirectUrl)) {
                return $this->createRedirectResponse($redirectUrl);
            } else {
                // Something weird is going on e.g. a publish file without a physical file
                return $this->createMissingResponse();
            }
        }

        // Deny if file is protected and denied
        if ($protected->has($asset)) {
            return $this->createDeniedResponse();
        }

        // We've looked everywhere and couldn't find a file
        return $this->createMissingResponse();
    }

    /**
     * Given a FileID, try to find an equivalent file ID for a more recent file using the latest format.
     * @param string $asset
     * @return string
     */
    private function searchForEquivalentFileID($asset)
    {
        // If File is not versionable, let's bail
        if (!class_exists(Versioned::class) || !File::has_extension(Versioned::class)) {
            return '';
        }

        $parsedFileID = $this->parseFileID($asset);
        if ($parsedFileID && $parsedFileID['Hash']) {
            // Try to find a live version of this file
            $stage = Versioned::get_stage();
            Versioned::set_stage(Versioned::LIVE);
            $file = File::get()->filter(['FileFilename' => $parsedFileID['Filename']])->first();
            Versioned::set_stage($stage);

            // If we found a matching live file, let's see if our hash was publish at any point
            if ($file) {
                $oldVersionCount = $file->allVersions(
                    [
                        ['"FileHash" like ?' => DB::get_conn()->escapeString($parsedFileID['Hash']) . '%'],
                        ['not "FileHash" like ?' => DB::get_conn()->escapeString($file->getHash())],
                        '"WasPublished"' => true
                    ],
                    "",
                    1
                )->count();
                // Our hash was published at some other stage
                if ($oldVersionCount > 0) {
                    return $this->getFileID($file->getFilename(), $file->getHash(), $parsedFileID['Variant']);
                }
            }
        }

        // Let's see if $asset is a legacy URL that can be map to a current file
        $parsedFileID = $this->parseLegacyFileID($asset);
        if ($parsedFileID) {
            $filename = $parsedFileID['Filename'];
            $variant = $parsedFileID['Variant'];
            // Let's try to match the plain file name
            $stage = Versioned::get_stage();
            Versioned::set_stage(Versioned::LIVE);
            $file = File::get()->filter(['FileFilename' => $filename])->first();
            Versioned::set_stage($stage);

            if ($file) {
                return $this->getFileID($filename, $file->getHash(), $variant);
            }
        }

        return '';
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

<?php

namespace SilverStripe\Assets;

use BadMethodCallException;
use Intervention\Image\Colors\Rgb\Channels\Alpha;
use Intervention\Image\Colors\Rgb\Color;
use Intervention\Image\Drivers\AbstractEncoder;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\Exceptions\EncoderException;
use Intervention\Image\Interfaces\ImageInterface as InterventionImage;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\StreamInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Config\Config;

class InterventionBackend implements Image_Backend, Flushable
{
    use Configurable;

    /**
     * Cache prefix for marking
     */
    const CACHE_MARK = 'MARK_';

    /**
     * Cache prefix for dimensions
     */
    const CACHE_DIMENSIONS = 'DIMENSIONS_';

    /**
     * Is cache flushing enabled?
     */
    private static bool $flush_enabled = true;

    /**
     * How long to cache each error type
     *
     * Map of error type to config.
     * each config could be a single int (fixed cache time)
     * or list of integers (increasing scale)
     */
    private static array $error_cache_ttl = [
        InterventionBackend::FAILED_INVALID => 0, // Invalid file type should probably never be retried
        InterventionBackend::FAILED_MISSING => '5,10,20,40,80', // Missing files may be eventually available
        InterventionBackend::FAILED_UNKNOWN => 300, // Unknown (edge case). Maybe system error? Needs a flush?
    ];

    /**
     * This file is invalid because it is not image data, or it cannot
     * be processed by the given backend
     */
    const FAILED_INVALID = 'invalid';

    /**
     * This file is invalid as it is missing from the filesystem
     */
    const FAILED_MISSING = 'missing';

    /**
     * Some unknown error
     */
    const FAILED_UNKNOWN = 'unknown';

    /**
     * Configure where cached intervention files will be stored
     *
     */
    private static string $local_temp_path = TEMP_PATH;

    private ?AssetContainer $container = null;

    private ?InterventionImage $image;

    private int $quality = AbstractEncoder::DEFAULT_QUALITY;

    private ?ImageManager $manager = null;

    private ?CacheInterface $cache = null;

    private ?string $tempPath = null;

    public function __construct(?AssetContainer $assetContainer = null)
    {
        $this->setAssetContainer($assetContainer);
    }

    /**
     * Get the temporary local path for this image
     */
    public function getTempPath(): ?string
    {
        return $this->tempPath;
    }

    /**
     * Set the temporary local path for this image
     */
    public function setTempPath(string $path): static
    {
        $this->tempPath = $path;
        return $this;
    }

    public function getCache(): CacheInterface
    {
        if (!$this->cache) {
            $this->setCache(Injector::inst()->get(CacheInterface::class . '.InterventionBackend_Manipulations'));
        }
        return $this->cache;
    }

    public function setCache(CacheInterface $cache): static
    {
        $this->cache = $cache;
        return $this;
    }

    public function getAssetContainer(): ?AssetContainer
    {
        return $this->container;
    }

    public function setAssetContainer(?AssetContainer $assetContainer): static
    {
        $this->setImageResource(null);
        $this->container = $assetContainer;
        return $this;
    }

    public function getImageManager(): ImageManager
    {
        if (!$this->manager) {
            $this->setImageManager(Injector::inst()->create(ImageManager::class));
        }
        return $this->manager;
    }

    public function setImageManager(ImageManager $manager): static
    {
        $this->manager = $manager;
        return $this;
    }

    /**
     * Populate the backend with a given object
     *
     * @param AssetContainer $assetContainer Object to load from
     */
    public function loadFromContainer(AssetContainer $assetContainer): static
    {
        return $this->setAssetContainer($assetContainer);
    }

    /**
     * Get the currently assigned image resource, or generates one if not yet assigned.
     * Note: This method may return null if error
     */
    public function getImageResource(): ?InterventionImage
    {
        // Get existing resource
        if ($this->image) {
            return $this->image;
        }

        // Load container
        $assetContainer = $this->getAssetContainer();
        if (!$assetContainer) {
            return null;
        }

        // Avoid repeat load of broken images
        $hash = $assetContainer->getHash();
        $variant = $assetContainer->getVariant();
        if ($this->hasFailed($hash, $variant)) {
            return null;
        }

        // Validate stream is readable
        // Note: Mark failed regardless of whether a failed stream is exceptional or not
        $error = InterventionBackend::FAILED_MISSING;
        try {
            $stream = $assetContainer->getStream();
            if ($this->isStreamReadable($stream)) {
                $error = null;
            } else {
                return null;
            }
        } finally {
            if ($error) {
                $this->markFailed($hash, $variant, $error);
            }
        }

        // Handle resource
        $error = InterventionBackend::FAILED_UNKNOWN;
        try {
            // write the file to a local path so we can extract exif data if it exists.
            // Currently exif data can only be read from file paths and not streams
            $tempPath = $this->config()->get('local_temp_path') ?? TEMP_PATH;
            $path = tempnam($tempPath ?? '', 'interventionimage_');
            if ($extension = pathinfo($assetContainer->getFilename() ?? '', PATHINFO_EXTENSION)) {
                //tmpnam creates a file, we should clean it up if we are changing the path name
                unlink($path ?? '');
                $path .= "." . $extension;
            }
            $bytesWritten = file_put_contents($path ?? '', $stream);
            // if we fail to write, then load from stream
            if ($bytesWritten === false) {
                $resource = $this->getImageManager()->read($stream);
            } else {
                $this->setTempPath($path);
                $resource = $this->getImageManager()->read($path);
            }

            $this->setImageResource($resource);
            $this->markSuccess($hash, $variant);
            $this->warmCache($hash, $variant);
            $error = null;
            return $resource;
        } catch (DecoderException $ex) {
            // Handle unsupported image encoding on load (will be marked as failed)
            // Unsupported exceptions are handled without being raised as exceptions
            $error = InterventionBackend::FAILED_INVALID;
        } finally {
            if ($error) {
                $this->markFailed($hash, $variant, $error);
            }
        }
        return null;
    }

    /**
     * Populate the backend from a local path
     */
    public function loadFrom(string $path): static
    {
        // Avoid repeat load of broken images
        $hash = sha1($path ?? '');
        if ($this->hasFailed($hash, null)) {
            return $this;
        }

        // Handle resource
        $error = InterventionBackend::FAILED_UNKNOWN;
        try {
            $this->setImageResource($this->getImageManager()->read($path));
            $this->markSuccess($hash, null);
            $error = null;
        } catch (DecoderException $ex) {
            // Handle unsupported image encoding on load (will be marked as failed)
            // Unsupported exceptions are handled without being raised as exceptions
            $error = InterventionBackend::FAILED_INVALID;
        } finally {
            if ($error) {
                $this->markFailed($hash, null, $error);
            }
        }

        return $this;
    }

    /**
     * @inheritDoc
     *
     * @param InterventionImage $image
     */
    public function setImageResource($image): static
    {
        if ($image && !is_a($image, InterventionImage::class)) {
            throw new InvalidArgumentException('$image must be an instance of ' . InterventionImage::class);
        }
        $this->image = $image;
        if ($image === null) {
            // remove our temp file if it exists
            if (file_exists($this->getTempPath() ?? '')) {
                unlink($this->getTempPath());
            }
        }
        return $this;
    }

    /**
     * @inheritDoc
     *
     * @throws BadMethodCallException If image isn't valid
     */
    public function writeToStore(AssetStore $assetStore, string $filename, ?string $hash = null, ?string $variant = null, array $config = []): array
    {
        try {
            $resource = $this->getImageResource();
            if (!$resource) {
                throw new BadMethodCallException("Cannot write corrupt file to store");
            }

            // Make sure we're using the extension of the variant file, which can differ from the original file
            $url = $assetStore->getAsURL($filename, $hash, $variant, false);
            $extension = pathinfo($url, PATHINFO_EXTENSION);
            // Save file
            $result = $assetStore->setFromString(
                $resource->encodeByExtension($extension, quality: $this->getQuality())->toString(),
                $filename,
                $hash,
                $variant,
                $config
            );

            // Warm cache for the result
            if ($result) {
                $this->warmCache($result['Hash'], $result['Variant']);
            }

            return $result;
        } catch (EncoderException $e) {
            return [];
        }
    }

    /**
     * @inheritDoc
     *
     * @throws BadMethodCallException If image isn't valid
     */
    public function writeTo(string $path): bool
    {
        try {
            $resource = $this->getImageResource();
            if (!$resource) {
                throw new BadMethodCallException("Cannot write corrupt file to store");
            }
            $resource->save($path, $this->getQuality());
        } catch (EncoderException $e) {
            return false;
        }
        return true;
    }

    /**
     * @inheritDoc
     */
    public function getQuality(): int
    {
        return $this->quality;
    }

    /**
     * Return dimensions as array with cache enabled
     *
     * Returns a two-length array with width and height
     */
    protected function getDimensions(): array
    {
        // Default result
        $result = [0, 0];

        // If we have a resource already loaded, this means we have modified the resource since the
        // original image was loaded. This means the "Variant" tuple key is out of date, and we don't
        // have a reliable cache key to load from, or save to. If we use the original tuple as a key,
        // we would run the risk of overwriting the original dimensions in the cache, with the values
        // of the resized instead.
        // Instead, we use the immediately available dimensions attached to this resource, and we will
        // rely on cache warming in writeToStore to save these values, where the "Variant" becomes available,
        // before the next time this variant is loaded into memory.
        $resource = $this->image;
        if ($resource) {
            return $this->getResourceDimensions($resource);
        }

        // Check if we have a container
        $container = $this->getAssetContainer();
        if (!$container) {
            return $result;
        }

        // Check cache for unloaded image
        $cache = $this->getCache();
        $key = $this->getDimensionCacheKey($container->getHash(), $container->getVariant());
        if ($cache->has($key)) {
            return $cache->get($key);
        }

        // Cache-miss
        $resource = $this->getImageResource();
        if ($resource) {
            $result = $this->getResourceDimensions($resource);
            $cache->set($key, $result);
        }
        return $result;
    }

    /**
     * Get dimensions from the given resource
     */
    protected function getResourceDimensions(InterventionImage $resource): array
    {
        return [
            $resource->width(),
            $resource->height(),
        ];
    }

    /**
     * Cache key for recording errors
     */
    protected function getErrorCacheKey(string $hash, ?string $variant = null): string
    {
        return InterventionBackend::CACHE_MARK . sha1($hash . '-' . $variant);
    }

    /**
     * Cache key for dimensions for given container
     */
    protected function getDimensionCacheKey(string $hash, ?string $variant = null): string
    {
        return InterventionBackend::CACHE_DIMENSIONS . sha1($hash . '-' . $variant);
    }

    /**
     * Warm dimension cache for the given asset
     */
    protected function warmCache(string $hash, ?string $variant = null): void
    {
        // Warm dimension cache
        $key = $this->getDimensionCacheKey($hash, $variant);
        $resource = $this->getImageResource();
        if ($resource) {
            $result = $this->getResourceDimensions($resource);
            $this->getCache()->set($key, $result);
        }
    }

    /**
     * @inheritDoc
     */
    public function getWidth(): int
    {
        list($width) = $this->getDimensions();
        return (int)$width;
    }

    /**
     * @inheritDoc
     */
    public function getHeight(): int
    {
        list(, $height) = $this->getDimensions();
        return (int)$height;
    }

    /**
     * @inheritDoc
     */
    public function setQuality(int $quality): static
    {
        $this->quality = $quality;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function resize(int $width, int $height): ?static
    {
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width, $height) {
                return $resource->resize($width, $height);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function resizeRatio(int $width, int $height, bool $useAsMinimum = false): ?static
    {
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width, $height, $useAsMinimum) {
                if ($useAsMinimum) {
                    return $resource->scale($width, $height);
                }
                return $resource->scaleDown($width, $height);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function resizeByWidth(int $width): ?static
    {
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width) {
                return $resource->scale($width);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function resizeByHeight(int $height): ?static
    {
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($height) {
                return $resource->scale(height: $height);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function paddedResize(string $width, string $height, string $backgroundColour = 'FFFFFF', int $transparencyPercent = 0): ?static
    {
        $resource = $this->getImageResource();
        if (!$resource) {
            return null;
        }

        if ($transparencyPercent < 0 || $transparencyPercent > 100) {
            throw new InvalidArgumentException('$transparencyPercent must be between 0 and 100. Got ' . $transparencyPercent);
        }

        $bgColour = Color::create($backgroundColour);
        // The Color class is immutable, so we have to instantiate a new one to set the alpha channel.
        // No need to do that if both the $backgroundColor and $transparencyPercent are 0.
        if ($bgColour->channel(Alpha::class)->value() !== 0 && $transparencyPercent !== 0) {
            $channels = $bgColour->channels();
            $alpha = (int) round(255 * (1 - ($transparencyPercent * 0.01)));
            $bgColour = new Color($channels[0]->value(), $channels[1]->value(), $channels[2]->value(), $alpha);
        }

        // resize the image maintaining the aspect ratio and pad out the canvas
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width, $height, $bgColour) {
                return $resource->contain($width, $height, $bgColour, 'center');
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function croppedResize(int $width, int $height, string $position = 'center'): ?static
    {
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width, $height, $position) {
                return $resource->cover($width, $height, $position);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function crop(int $top, int $left, int $width, int $height, string $position = 'top-left', string $backgroundColour = 'FFFFFF'): ?static
    {
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($top, $left, $height, $width, $position, $backgroundColour) {
                return $resource->crop($width, $height, $left, $top, $backgroundColour, $position);
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function setAllowsAnimationInManipulations(bool $allow): static
    {
        $this->getImageManager()->driver()->config()->decodeAnimation = $allow;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getAllowsAnimationInManipulations(): bool
    {
        return $this->getImageManager()->driver()->config()->decodeAnimation;
    }

    /**
     * @inheritDoc
     */
    public function getIsAnimated(): bool
    {
        if (!$this->getAllowsAnimationInManipulations()) {
            return false;
        }
        return $this->getImageResource()?->isAnimated() ?? false;
    }

    /**
     * @inheritDoc
     */
    public function removeAnimation(int|string $position): ?static
    {
        if (!$this->getAllowsAnimationInManipulations()) {
            return $this;
        }
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($position) {
                return $resource->removeAnimation($position);
            }
        );
    }

    /**
     * Modify this image backend with either a provided resource, or transformation
     *
     * @param InterventionImage|callable $resourceOrTransformation Either the resource to assign to the clone,
     * or a function which takes the current resource as a parameter
     */
    protected function createCloneWithResource($resourceOrTransformation): ?static
    {
        // No clone with no argument
        if (!$resourceOrTransformation) {
            return null;
        }

        // Handle transformation function
        if (is_callable($resourceOrTransformation)) {
            // Fail if resource not available
            $resource = $this->getImageResource();
            if (!$resource) {
                return null;
            }

            // Note: Closure may simply modify the resource rather than return a new one
            $resource = clone $resource;
            $resource = call_user_func($resourceOrTransformation, $resource) ?: $resource;

            // Clone with updated resource
            return $this->createCloneWithResource($resource);
        }

        // Ensure result is of a valid type
        if (!$resourceOrTransformation instanceof InterventionImage) {
            throw new InvalidArgumentException("Invalid resource type");
        }

        // Create clone
        $clone = clone $this;
        $clone->setImageResource($resourceOrTransformation);
        return $clone;
    }

    /**
     * Clear any cached errors / metadata for this image
     */
    protected function markSuccess(string $hash, ?string $variant = null): void
    {
        $key = $this->getErrorCacheKey($hash, $variant);
        $this->getCache()->deleteMultiple([
            $key.'_reason',
            $key.'_ttl'
        ]);
    }

    /**
     * Mark this image as failed to load
     *
     * @param string $hash Hash of original file being manipluated
     * @param string|null $variant Variant being loaded
     * @param string $reason Reason this file is failed
     */
    protected function markFailed(string $hash, ?string $variant = null, string $reason = InterventionBackend::FAILED_UNKNOWN): void
    {
        $key = $this->getErrorCacheKey($hash, $variant);

        // Get TTL for error
        $errorTTLs = $this->config()->get('error_cache_ttl');
        $ttl = isset($errorTTLs[$reason]) ? $errorTTLs[$reason] : $errorTTLs[InterventionBackend::FAILED_UNKNOWN];

        // Detect increasing waits
        if (is_string($ttl) && strstr($ttl ?? '', ',')) {
            $ttl = preg_split('#\s*,\s*#', $ttl ?? '');
        }
        if (is_array($ttl)) {
            $index = min(
                $this->getCache()->get($key.'_ttl', -1) + 1,
                count($ttl ?? []) - 1
            );
            $this->getCache()->set($key.'_ttl', $index);
            $ttl = $ttl[$index];
        }
        if (!is_numeric($ttl)) {
            throw new LogicException("Invalid TTL {$ttl}");
        }
        // Treat 0 as unlimited
        $ttl = $ttl ? (int)$ttl : null;
        $this->getCache()->set($key.'_reason', $reason, $ttl);
    }

    /**
     * Determine reason this file could not be loaded.
     * Will return one of the FAILED_* constant values, or null if not failed
     *
     * @param string $hash Hash of the original file being manipulated
     */
    protected function hasFailed(string $hash, ?string $variant = null): ?string
    {
        $key = $this->getErrorCacheKey($hash, $variant);
        return $this->getCache()->get($key.'_reason', null);
    }

    /**
     * Make sure we clean up the image resource when this object is destroyed
     */
    public function __destruct()
    {
        // remove our temp file if it exists
        if (file_exists($this->getTempPath() ?? '')) {
            unlink($this->getTempPath() ?? '');
        }
    }

    /**
     * This function is triggered early in the request if the "flush" query
     * parameter has been set. Each class that implements Flushable implements
     * this function which looks after it's own specific flushing functionality.
     *
     * @see FlushRequestFilter
     */
    public static function flush(): void
    {
        if (Config::inst()->get(static::class, 'flush_enabled')) {
            /** @var CacheInterface $cache */
            $cache = Injector::inst()->get(CacheInterface::class . '.InterventionBackend_Manipulations');
            $cache->clear();
        }
    }

    /**
     * Validate the stream resource is readable
     */
    protected function isStreamReadable(mixed $stream): bool
    {
        if (empty($stream)) {
            return false;
        }
        if ($stream instanceof StreamInterface) {
            return $stream->isReadable();
        }

        // Ensure resource is stream type
        if (!is_resource($stream)) {
            return false;
        }
        if (get_resource_type($stream) !== 'stream') {
            return false;
        }

        // Ensure stream is readable
        $meta = stream_get_meta_data($stream);
        return isset($meta['mode']) && (strstr($meta['mode'] ?? '', 'r') || strstr($meta['mode'] ?? '', '+'));
    }
}

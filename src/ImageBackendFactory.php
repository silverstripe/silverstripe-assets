<?php

namespace SilverStripe\Assets;

use BadMethodCallException;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Core\Injector\Factory;

/**
 * Creates backends for images as necessary, avoiding redundant asset writes and loads
 */
class ImageBackendFactory implements Factory
{
    /**
     * In memory cache keyed by hash/variant
     *
     * @var array
     */
    protected $cache = [];

    /**
     * @var Factory
     */
    protected $creator = null;

    public function __construct(Factory $creator)
    {
        $this->creator = $creator;
    }

    /**
     * Creates a new service instance.
     *
     * @param string $service The class name of the service.
     * @param array $params The constructor parameters.
     * @return object The created service instances.
     */
    public function create($service, array $params = [])
    {
        /** @var AssetContainer|null $assetContainer */
        $assetContainer = reset($params);

        // If no asset container was passed in, create a new uncached image backend
        if (!$assetContainer) {
            return $this->creator->create($service, $params);
        }

        if (!($assetContainer instanceof AssetContainer)) {
            throw new BadMethodCallException("Can only create Image_Backend for " . AssetContainer::class);
        }

        // Check cache
        $key = sha1($assetContainer->getHash().'-'.$assetContainer->getVariant());
        if (array_key_exists($key, $this->cache ?? [])) {
            return $this->cache[$key];
        }

        // Verify file exists before creating backend
        $backend = null;
        if ($assetContainer->exists() && $assetContainer->getIsImage()) {
            $backend = $this->creator->create($service, $params);
        }

        // Cache
        $this->cache[$key] = $backend;
        return $backend;
    }
}

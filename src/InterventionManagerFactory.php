<?php

namespace SilverStripe\Assets;

use Intervention\Image\ImageManager;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Injector\Injector;

/**
 * Instantiates an intervention ImageManager class with its own driver instance.
 * This is necessary to avoid having a singleton of the driver used across all image managers,
 * which is what happens if we try to do this using `%$InterventionImageDriver` yaml config.
 */
class InterventionManagerFactory implements Factory
{
    public function create(string $service, array $params = []): ImageManager
    {
        return ImageManager::withDriver(Injector::inst()->create('InterventionImageDriver'), ...$params);
    }
}

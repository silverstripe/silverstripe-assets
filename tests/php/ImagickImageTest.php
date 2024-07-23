<?php

namespace SilverStripe\Assets\Tests;

use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\SilverStripeServiceConfigurationLocator;

class ImagickImageTest extends ImageTest
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded("imagick")) {
            $this->markTestSkipped("The Imagick extension is not available.");
            return;
        }

        // this is a hack because the service locator cahces config settings meaning you can't properly override them
        Injector::inst()->setConfigLocator(new SilverStripeServiceConfigurationLocator());
        Config::modify()->set(Injector::class, ImageManager::class, [
            'constructor' => [
                '%$' . ImagickDriver::class,
            ],
        ]);
    }

    public function testDriverType()
    {
        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        /** @var InterventionBackend $backend */
        $backend = $image->getImageBackend();
        $this->assertInstanceOf(ImagickDriver::class, $backend->getImageManager()->driver());
    }
}

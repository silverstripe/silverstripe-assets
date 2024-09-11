<?php

namespace SilverStripe\Assets\Tests;

use Intervention\Image\Drivers\Gd\Driver as GDDriver;
use Intervention\Image\ImageManager;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\SilverStripeServiceConfigurationLocator;

class GDImageTest extends ImageTestBase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded("gd")) {
            $this->markTestSkipped("The GD extension is required");
            return;
        }

        // this is a hack because the service locator cahces config settings meaning you can't properly override them
        Injector::inst()->setConfigLocator(new SilverStripeServiceConfigurationLocator());
        Config::modify()->set(Injector::class, ImageManager::class, [
            'constructor' => [
                '%$' . GDDriver::class,
            ],
        ]);
    }

    public function testDriverType()
    {
        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        /** @var InterventionBackend $backend */
        $backend = $image->getImageBackend();
        $this->assertInstanceOf(GDDriver::class, $backend->getImageManager()->driver());
    }

    public function testGetTagWithTitle()
    {
        parent::testGetTagWithTitle();
    }
}

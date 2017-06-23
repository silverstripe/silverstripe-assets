<?php

namespace SilverStripe\Assets\Tests;

use Intervention\Image\ImageManager;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\ImagickBackend;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\SilverStripeServiceConfigurationLocator;

class ImagickImageTest extends ImageTest
{
    public function setUp()
    {
        parent::setUp();

        if (!extension_loaded("imagick")) {
            $this->markTestSkipped("The Imagick extension is not available.");
            return;
        }

        /** @skipUpgrade */
        // this is a hack because the service locator cahces config settings meaning you can't properly override them
        Injector::inst()->setConfigLocator(new SilverStripeServiceConfigurationLocator());
        Config::modify()->set(Injector::class, ImageManager::class, [
            'constructor' => [
                [ 'driver' => 'imagick' ],
            ],
        ]);
    }

    public function testDriverType()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $this->assertEquals('imagick', $image->getImageBackend()->getImageManager()->config['driver']);
    }
}

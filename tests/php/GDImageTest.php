<?php

namespace SilverStripe\Assets\Tests;

require_once __DIR__  . "/ImageTest.php";

use Intervention\Image\ImageManager;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\SilverStripeServiceConfigurationLocator;

class GDImageTest extends ImageTest
{

    public function setUp()
    {
        parent::setUp();

        if (!extension_loaded("gd")) {
            $this->markTestSkipped("The GD extension is required");
            return;
        }

        /** @skipUpgrade */
        // this is a hack because the service locator cahces config settings meaning you can't properly override them
        Injector::inst()->setConfigLocator(new SilverStripeServiceConfigurationLocator());
        Config::modify()->set(Injector::class, ImageManager::class, [
            'constructor' => [
                [ 'driver' => 'gd' ],
            ],
        ]);
    }

    public function testDriverType()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $this->assertEquals('gd', $image->getImageBackend()->getImageManager()->config['driver']);
    }
}

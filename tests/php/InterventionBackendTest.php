<?php

namespace SilverStripe\Assets\Tests;

use Intervention\Image\Exception\NotReadableException;
use Psr\Log\LoggerInterface;
use Psr\Log\Test\TestLogger;
use ReflectionMethod;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

class InterventionBackendTest extends SapphireTest
{
    /**
     * @var string
     */
    protected static $fixture_file = 'InterventionBackendTest.yml';

    public function testExceptionsCanGetLogged()
    {
        $logger = new TestLogger();
        Injector::inst()->registerService($logger, LoggerInterface::class . '.InterventionBackend');
        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        // We need to use an actual image otherwise we can't get the backend instance
        $image->setFromLocalFile(__DIR__ . DIRECTORY_SEPARATOR . 'ImageTest' . DIRECTORY_SEPARATOR . 'test-image.png');
        /** @var InterventionBackend $backend */
        $backend = $image->getImageBackend();

        // We're using reflection here because the method is private
        $reflectedMethod = new ReflectionMethod(
            InterventionBackend::class,
            'logException'
        );
        $reflectedMethod->setAccessible(true);
        // Send off our exception
        $reflectedMethod->invokeArgs($backend, [new NotReadableException('File not readable')]);

        // Check it was recorded
        $this->assertTrue($logger->hasErrorRecords());
        $this->assertTrue($logger->hasDebugThatContains('File not readable'));

        // Clean up afterwards
        $image->delete();
    }
}

<?php

namespace SilverStripe\Assets\Tests\Services;

use SilverStripe\Assets\Services\ReadOnlyCacheService;
use SilverStripe\Dev\SapphireTest;

class ReadOnlyCacheServiceTest extends SapphireTest
{

    public function testGetSetEnabled()
    {
        $service = ReadOnlyCacheService::singleton();
        $this->assertFalse($service->getEnabled());
        $service->setEnabled(true);
        $this->assertTrue($service->getEnabled());
    }

    public function testGetSetHasValue()
    {
        $service = ReadOnlyCacheService::singleton();
        $this->assertFalse($service->hasValue(['A', 'B'], ['1', '2']));
        $service->setValue(['A', 'B'], ['1', '2'], 'xyz');
        $this->assertTrue($service->hasValue(['A', 'B'], ['1', '2']));
        $this->assertEquals('xyz', $service->getValue(['A', 'B'], ['1', '2']));
    }

    public function testReset()
    {
        $service = ReadOnlyCacheService::singleton();
        $service->setValue(['A', 'B'], ['1', '2'], 'xyz');
        $service->setValue(['C', 'D'], ['3', '4'], 'wvu');
        $this->assertTrue($service->hasValue(['A', 'B'], ['1', '2']));
        $service->reset(['A', 'B']);
        $this->assertFalse($service->hasValue(['A', 'B'], ['1', '2']));
        $this->assertTrue($service->hasValue(['C', 'D'], ['3', '4']));
        $service->reset();
        $this->assertFalse($service->hasValue(['C', 'D'], ['3', '4']));
    }
}

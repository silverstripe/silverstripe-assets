<?php

namespace SilverStripe\Assets\Tests\ImageManipulationTest;

use SilverStripe\Core\Extension;

class LazyLoadAccessorExtension extends Extension
{
    public function getLazyLoadValueViaExtension(): bool
    {
        return $this->getOwner()->IsLazyLoaded();
    }
}

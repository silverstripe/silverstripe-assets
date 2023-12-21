<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\Assets\Upload_Validator;

/**
 * @skipUpgrade
 */
class UploadValidatorTest extends SapphireTest
{
    /**
     * {@inheritDoc}
     * @var bool
     */
    protected $usesDatabase = false;

    public function testUploadValidatorChaining()
    {
        $v = new Upload_Validator();

        $chain = $v->clearErrors()->setAllowedMaxFileSize(100)->setAllowedExtensions([
            'jpg'
        ]);

        $this->assertInstanceOf(Upload_Validator::class, $chain);
    }
}

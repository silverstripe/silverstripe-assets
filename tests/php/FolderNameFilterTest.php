<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\FolderNameFilter;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\View\Parsers\Transliterator;

class FolderNameFilterTest extends SapphireTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset to default
        FolderNameFilter::config()->set(
            'default_replacements',
            [
                '/\s/' => '-', // remove whitespace
                '/[^-_A-Za-z0-9+.]+/' => '', // remove non-ASCII chars, only allow alphanumeric plus dash, dot, and underscore
                '/_{2,}/' => '_', // remove duplicate underscores (since `__` is variant separator)
                '/-{2,}/' => '-', // remove duplicate dashes
                '/^[-_\.]+/' => '', // Remove all leading dots, dashes or underscores,
                '/\./' => '-', // replace dots with dashes
            ]
        );
    }

    public function testFilter()
    {
        $name = 'Brötchen  für allë-mit_Unterstrich!';
        $filter = new FolderNameFilter();
        $filter->setTransliterator(false);
        $this->assertEquals(
            'Brtchen-fr-all-mit_Unterstrich',
            $filter->filter($name)
        );
    }

    public function testFilterWithTransliterator()
    {
        $name = 'Brötchen  für allë-mit_Unterstrich!';
        $filter = new FolderNameFilter();
        $filter->setTransliterator(new Transliterator());
        $this->assertEquals(
            'Broetchen-fuer-alle-mit_Unterstrich',
            $filter->filter($name)
        );
    }

    public function testDotsAreReplacedWithDashes()
    {
        $name = 'foo.bar.baz';
        $filter = new FolderNameFilter();
        $this->assertEquals('foo-bar-baz', $filter->filter($name));
    }
}

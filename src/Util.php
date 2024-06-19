<?php

namespace SilverStripe\Assets;

class Util
{
    public static function rewindStream($resource): void
    {
        Util::checkIsResource($resource);
        if (ftell($resource) !== 0 && static::isSeekableStream($resource)) {
            rewind($resource);
        }
    }

    public static function isSeekableStream($resource): bool
    {
        Util::checkIsResource($resource);

        return stream_get_meta_data($resource)['seekable'];
    }

    private static function checkIsResource($resource): void
    {
        if (!is_resource($resource)) {
            throw new \InvalidArgumentException('$resource argument is not a valid resource');
        }
    }
}

<?php

namespace SilverStripe\S3\Cache;

use Psr\SimpleCache\CacheInterface;

/**
 * Allows a cache to be warmed from non-serializable content (e.g. streams)
 */
interface ContentWarmer extends CacheInterface
{
    /**
     * Warm cache from a local path
     *
     * @param string $key Key
     * @param string $path Path to the temp file
     */
    public function warmFromPath($key, $path);

    /**
     * Warm cache from local path
     *
     * @param string $key Key
     * @param string $data The file content
     * @return $this
     */
    public function warmFromString($key, $data);

    /**
     * Warm from a stream.
     * Note: non-seekable streams won't be warmed
     *
     * @param string $key Key
     * @param resource $stream
     * @return $this
     */
    public function warmFromStream($key, $stream);
}

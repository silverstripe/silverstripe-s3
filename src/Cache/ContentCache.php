<?php

namespace SilverStripe\S3\Cache;

use Exception;
use League\Flysystem\Util;
use Psr\SimpleCache\CacheInterface;

/**
 * Captures local files for immediate access, saving unnecessary back-end requests
 */
class ContentCache implements ContentWarmer
{
    /**
     * Backend cache for caching file paths
     *
     * @var CacheInterface
     */
    protected $locationCache = null;

    public function getLocationCache()
    {
        return $this->locationCache;
    }

    public function setLocationCache(CacheInterface $cache)
    {
        $this->locationCache = $cache;
        return $this;
    }

    /**
     * Cache of local temp files sent to the backend, keyed by SHA1
     *
     * @var array Array of cached file paths
     */
    protected $fileCache = [];

    /**
     * Warm cache from a local path
     *
     * @param string $key Key
     * @param string $path Path to the temp file
     */
    public function warmFromPath($key, $path)
    {
        $this->set($key, $path);
    }

    /**
     * Warm cache from local path
     *
     * @param string $key Key
     * @param string $data The file content
     * @return $this
     */
    public function warmFromString($key, $data)
    {
        // Avoid creating a temp file if we already have a valid cache
        if ($this->has($key)) {
            return $this;
        }

        // Send to path
        $path = $this->createTempFile();
        file_put_contents($path, $data);
        $this->warmFromPath($key, $path);
        return $this;
    }

    /**
     * Warm from a stream.
     * Note: non-seekable streams won't be warmed
     *
     * @param string $key Key
     * @param resource $stream
     * @return $this
     */
    public function warmFromStream($key, $stream)
    {
        // Avoid creating a temp file if we already have a valid cache
        if ($this->has($key)) {
            return $this;
        }

        // Convert to path
        $path = $this->getStreamAsFile($stream);
        if ($path) {
            $this->warmFromPath($key, $path);
        }
        return $this;
    }

    /**
     * Get stream as a file
     *
     * @param resource $stream
     * @return string Filename of resulting stream content, or null if not saveable
     * @throws Exception
     */
    protected function getStreamAsFile($stream)
    {
        // Can't warm non-rewindable streams
        if (!Util::isSeekableStream($stream)) {
            return null;
        }

        // Get temporary file and name
        $file = $this->createTempFile();
        $buffer = fopen($file, 'w');
        if (!$buffer) {
            throw new Exception("Could not create temporary file");
        }

        // Transfer from given stream
        Util::rewindStream($stream);
        stream_copy_to_stream($stream, $buffer);
        if (! fclose($buffer)) {
            throw new Exception("Could not write stream to temporary file");
        }

        // Ensure stream is unwound again
        Util::rewindStream($stream);
        return $file;
    }

    /**
     * Create a new temp file in case this is not available
     *
     * @return string
     */
    protected function createTempFile()
    {
        return tempnam(sys_get_temp_dir(), 'ssflysystem');
    }

    public function set($key, $value, $ttl = null)
    {
        $intervalValue = [
            'path' => $value,
            'mtime' => filemtime($value),
        ];
        return $this->getLocationCache()->set($key, $intervalValue, $ttl);
    }

    public function get($key, $default = null)
    {
        $result = $this->getLocationCache()->get($key);
        if (!$result) {
            return $default;
        }

        // Protect local file from being modified since last cache
        // as temp files may not be named based on sha1.
        $path = $result['path'];
        $mtime = $result['mtime'];
        if (file_exists($path) && filemtime($path) === $mtime) {
            return $path;
        }

        return $default;
    }

    public function delete($key)
    {
        return $this->getLocationCache()->delete($key);
    }

    public function clear()
    {
        return $this->getLocationCache()->clear();
    }

    public function getMultiple($keys, $default = null)
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }
        return $results;
    }

    public function setMultiple($values, $ttl = null)
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set($key, $value, $ttl) && $ok;
        }
        return $ok;
    }

    public function deleteMultiple($keys)
    {
        return $this->getLocationCache()->deleteMultiple($keys);
    }

    public function has($key)
    {
        return $this->get($key) !== null;
    }
}

<?php

namespace SilverStripe\S3\Adapter;

use finfo;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use SilverStripe\S3\Cache\ContentCache;
use Psr\SimpleCache\CacheInterface;
use SplFileInfo;

/**
 * Wrapper adapter over a backend
 */
class CacheAdapter implements AdapterInterface
{
    /**
     * Cache prefix for metadata array
     */
    const METADATA = 'metadata_';

    /**
     * Cache prefix for gnostic exists (null means unknown)
     */
    const HAS = 'has_';

    /**
     * Cache to use for metadata
     *
     * @var CacheInterface
     */
    protected $metadataCache = null;

    /**
     * Cache to use for content (optional)
     *
     * @var ContentCache
     */
    protected $contentCache = null;

    /**
     * @var AdapterInterface
     */
    protected $backend = null;

    /**
     * @return CacheInterface
     */
    public function getMetadataCache()
    {
        return $this->metadataCache;
    }

    /**
     * @param CacheInterface $metadataCache
     */
    public function setMetadataCache(CacheInterface $metadataCache)
    {
        $this->metadataCache = $metadataCache;
    }

    /**
     * Cache of local cached file paths.
     * This service will be both used to intercept uploaded content and extract metadata,
     * and can be used to bypass upstream content request calls for existant content.
     *
     * @return ContentCache
     */
    public function getContentCache()
    {
        return $this->contentCache;
    }

    /**
     * @param ContentCache $contentCache
     * @return $this
     */
    public function setContentCache(ContentCache $contentCache)
    {
        $this->contentCache = $contentCache;
        return $this;
    }

    /**
     * Failover adapter
     *
     * @return AdapterInterface
     */
    public function getBackend()
    {
        return $this->backend;
    }

    /**
     * Set failover adapter
     *
     * @param AdapterInterface $backend
     * @return $this
     */
    public function setBackend(AdapterInterface $backend)
    {
        $this->backend = $backend;
        return $this;
    }

    public function read($path)
    {
        // check if content is available
        $fileKey = sha1($path);
        $localPath = $this->getContentCache()->get($fileKey);
        if ($localPath) {
            $contents = file_get_contents($localPath);
            return ['type' => 'file', 'path' => $path, 'contents' => $contents];
        }

        // Cache miss, call and warm
        $result = $this->getBackend()->read($path);
        if ($result) {
            $this->getContentCache()->warmFromString($fileKey, $result['contents']);
            $this->setCachedHas($path, true);
        }
        return $result;
    }

    public function readStream($path)
    {
        // check if content is available
        $fileKey = sha1($path);
        $localPath = $this->getContentCache()->get($fileKey);
        if ($localPath) {
            $stream = fopen($localPath, 'rb');
            return ['type' => 'file', 'path' => $path, 'stream' => $stream];
        }

        // Cache miss, call and warm
        $result = $this->getBackend()->readStream($path);
        if ($result) {
            $this->getContentCache()->warmFromStream($fileKey, $result['stream']);
            $this->setCachedHas($path, true);
        }
        return $result;
    }

    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    public function writeStream($path, $resource, Config $config)
    {
        // Warm content cache
        $fileKey = sha1($path);
        $this->getContentCache()->warmFromStream($fileKey, $resource);

        // Update metadata cache
        $localPath = $this->getContentCache()->get($fileKey);
        $metadata = $this->getCachedMetadata($path) ?: null;
        if ($localPath) {
            $metadata = $this->approximateMetadata($path, $localPath);
        }

        // Warm / reset caches
        $this->setCachedHas($path, true);
        $this->setCachedMetadata($path, $metadata);

        return $this->getBackend()->writeStream($path, $resource, $config);
    }

    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    public function write($path, $contents, Config $config)
    {
        // Warm content cache
        $fileKey = sha1($path);
        $this->getContentCache()->warmFromString($fileKey, $contents);

        // Update metadata cache
        $localPath = $this->getContentCache()->get($fileKey);
        $metadata = $this->getCachedMetadata($path) ?: null;
        if ($localPath) {
            $metadata = $this->approximateMetadata($path, $localPath);
        }

        // Warm / reset caches
        $this->setCachedHas($path, true);
        $this->setCachedMetadata($path, $metadata);

        return $this->getBackend()->update($path, $contents, $config);
    }

    public function copy($path, $newpath)
    {
        $this->copyMetadata($path, $newpath);
        return $this->getBackend()->copy($path, $newpath);
    }

    public function rename($path, $newpath)
    {
        $this->copyMetadata($path, $newpath);
        $this->deleteMetadata($path);
        return $this->getBackend()->rename($path, $newpath);
    }

    public function delete($path)
    {
        $this->deleteMetadata($path);
        return $this->getBackend()->delete($path);
    }

    public function getVisibility($path)
    {
        // Not cached since subclasses hard-code
        return $this->getBackend()->getVisibility($path);
    }

    public function setVisibility($path, $visibility)
    {
        return $this->getBackend()->setVisibility($path, $visibility);
    }

    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    public function getMimeType($path)
    {
        return $this->getMetadata($path);
    }

    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get metadata.
     * Note: This method makes no assumption about existance in the backend,
     * and may find metadata for records that don't physically exist
     *
     * @param string $path
     * @return array|false
     */
    public function getMetadata($path)
    {
        // Check cached metadata
        $metadata = $this->getCachedMetadata($path);
        if (isset($metadata)) {
            return $metadata ?: false; // Convert empty arrays to false
        }

        // Check if we can approximate from local cache, or fail over to backend
        $localPath = $this->getContentCache()->get(sha1($path));
        if ($localPath) {
            $metadata = $this->approximateMetadata($path, $localPath);
        } else {
            // Fail over to call backend, and pre-warm has cache
            $metadata = $this->getBackendMetadata($path);
            $this->setCachedHas($path, !empty($metadata));
        }

        // Save metadata for next time
        $this->setCachedMetadata($path, $metadata);
        return $metadata;
    }

    /**
     * Determine existence of this record in the cached backend.
     * Note: this method will also cache metadat, but uses a slightly more
     * discerning implementation of getMetadata()
     *
     * @param string $path
     * @return bool
     */
    public function has($path)
    {
        // Check has-cache
        $exists = $this->getCachedHas($path);
        if (isset($exists)) {
            return $exists;
        }

        // Live request the backend cache
        $metadata = $this->getBackendMetadata($path);
        $has = !empty($metadata);
        $this->setCachedMetadata($path, $metadata);
        $this->setCachedHas($path, $has);
        return $has;
    }

    public function deleteDir($dirname)
    {
        return $this->getBackend()->deleteDir($dirname);
    }

    public function createDir($dirname, Config $config)
    {
        return $this->createDir($dirname, $config);
    }

    public function listContents($directory = '', $recursive = false)
    {
        return $this->getBackend()->listContents($directory, $recursive);
    }

    /**
     * Copy metadata from one path to another
     *
     * @param string $path
     * @param string $newPath
     */
    protected function copyMetadata($path, $newPath)
    {
        // Share content cache
        $fileKey = sha1($path);
        $newKey = sha1($newPath);
        $localPath = $this->getContentCache()->get($fileKey);
        if ($localPath) {
            $this->getContentCache()->set($newKey, $localPath);
        }

        // Share metadata cache (or infer from cached content)
        $metadata = $this->getCachedMetadata($path) ?: [];
        if ($metadata || $localPath) {
            // Combine metadata from source with destination
            // Either an existing $metadata or $localPath should be enough
            // to generate enough data to cache
            $newMetadata = array_merge(
                $metadata,
                $this->approximateMetadata($newPath, $localPath)
            );
            $newMetadata['timestamp'] = time();
            $this->setCachedMetadata($newPath, $newMetadata);
        }

        // Mark new location as has = true
        $this->setCachedHas($newPath, true);
    }

    /**
     * Remove metadata from a file we know doesn't exist anymore
     *
     * @param string $path
     */
    public function deleteMetadata($path)
    {
        $this->setCachedMetadata($path, false);
        $this->setCachedHas($path, false);
        $this->getContentCache()->delete(sha1($path));
    }

    /**
     * Build metadata from local path.
     * Note: If $cachePath is not provided, only minimal metadata can be inferred from
     * the local path.
     *
     * @param string $path
     * @param string $cachePath Optional local file to get extra information from
     * @return array
     */
    protected function approximateMetadata($path, $cachePath = null)
    {
        // Get metadata from path
        $pathInfo = pathinfo($path);
        unset($pathInfo['filename']);
        $metadata = array_merge($pathInfo, [
            'type' => 'file',
            'path' => $path,
        ]);
        if (!$cachePath) {
            return $metadata;
        }

        // Get metadata from physical file
        $info = new SplFileInfo($cachePath);
        $metadata = array_merge($metadata, [
            'type' => $info->getType(),
            'timestamp' => $info->getMTime()
        ]);

        // Get file specific metadata
        if ($metadata['type'] === 'file') {
            $finfo = new Finfo(FILEINFO_MIME_TYPE);
            $metadata['mimetype'] = $finfo->file($cachePath);
            $metadata['size'] = $info->getSize();
        }
        return $metadata;
    }

    /**
     * Get metadata for the cache
     *
     * @param string $path
     * @return array|false|null
     */
    protected function getCachedMetadata($path)
    {
        $key = self::METADATA . sha1($path);
        return $this->getMetadataCache()->get($key);
    }

    /**
     * Get full metadata for the backend item.
     * Note that for some backends, a lot of extra work can be done,
     * but it does ensure a complete metadata is cached for each entry.
     *
     * @param string $path
     * @return array|false
     */
    protected function getBackendMetadata($path)
    {
        // Fail over to call backend
        $metadata = $this->getBackend()->getMetadata($path);
        if (!$metadata) {
            return false;
        }

        // Some backends need extra calls to merge in certain fields
        if (!isset($metadata['size'])) {
            $metadata = array_merge($metadata, $this->getBackend()->getSize($path));
        }
        if (!isset($metadata['mimetype'])) {
            $metadata = array_merge($metadata, $this->getBackend()->getMimetype($path));
        }
        if (!isset($metadata['timestamp'])) {
            $metadata = array_merge($metadata, $this->getBackend()->getTimestamp($path));
        }

        return $metadata;
    }

    /**
     * Set metadata in cache
     *
     * @param string $path
     * @param array|false|null $metadata
     * @return $this
     */
    protected function setCachedMetadata($path, $metadata)
    {
        $key = self::METADATA . sha1($path);
        if (isset($metadata)) {
            $this->getMetadataCache()->set($key, $metadata);
        } else {
            $this->getMetadataCache()->delete($key);
        }
        return $this;
    }

    /**
     * Check if cache knows if this file exists.
     * Null means it doesn't know.
     *
     * @param string $path
     * @return bool|null
     */
    protected function getCachedHas($path)
    {
        $key = self::HAS . sha1($path);
        return $this->getMetadataCache()->get($key);
    }

    /**
     * Tell cache that we know if this file exists
     *
     * @param string $path
     * @param bool|null $exists
     * @return $this
     */
    protected function setCachedHas($path, $exists)
    {
        $key = self::HAS . sha1($path);
        if (isset($exists)) {
            $this->getMetadataCache()->set($key, $exists);
        } else {
            $this->getMetadataCache()->delete($key);
        }
        return $this;
    }
}

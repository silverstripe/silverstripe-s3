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
     * Cache prefix for visibility (not strictly a part of metadata)
     */
    const VISIBILITY = 'visibility_';

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

        // Warm metadata cache
        $localPath = $this->getContentCache()->get($fileKey);
        if ($localPath) {
            $metadata = $this->approximateMetadata($path, $localPath);
            $this->getMetadataCache()->set(self::METADATA . $fileKey, $metadata);
        }

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

        // Warm metadata cache
        $localPath = $this->getContentCache()->get($fileKey);
        if ($localPath) {
            $metadata = $this->approximateMetadata($path, $localPath);
            $this->getMetadataCache()->set(self::METADATA . $fileKey, $metadata);
        }

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
        $fileKey = sha1($path);
        $visibility = $this->getMetadataCache()->get(self::VISIBILITY . $fileKey);
        if ($visibility) {
            return ['path' => $path, 'visibility' => $visibility];
        }

        // Get or set visibility (signature is the same)
        $result = $this->getBackend()->getVisibility($path);
        if ($result) {
            $this->getMetadataCache()->set(
                self::VISIBILITY . $fileKey,
                $result['visibility']
            );
        }
        return $result;
    }

    public function setVisibility($path, $visibility)
    {
        // Get or set visibility (signature is the same)
        $fileKey = sha1($path);
        $this->getMetadataCache()->set(self::VISIBILITY . $fileKey, $visibility);
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

    public function getMetadata($path)
    {
        // Ccheck cached metadata
        $fileKey = sha1($path);
        $metadata = $this->getMetadataCache()->get(self::METADATA . $fileKey);
        if (isset($metadata)) {
            return $metadata ?: false; // Convert empty arrays to false
        }

        // Check if we can approximate from local cache
        $localPath = $this->getContentCache()->get($fileKey);
        if ($localPath) {
            $metadata = $this->approximateMetadata($path, $localPath);
        } else {
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
        }

        // Save metadata for next time
        $this->getMetadataCache()->set(self::METADATA . $fileKey, $metadata);
        return $metadata;
    }

    public function has($path)
    {
        $metadata = $this->getMetadata($path);
        return !empty($metadata);
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
     * @param string $newpath
     */
    protected function copyMetadata($path, $newpath)
    {
        // Move or copy metadata
        $fileKey = sha1($path);
        $newKey = sha1($newpath);

        // Share content cache across to destination
        $localPath = $this->getContentCache()->get($fileKey);
        if ($localPath) {
            $this->getContentCache()->set($newKey, $localPath);
        }

        // Share metadata cache
        $metadata = $this->getMetadataCache()->get(self::METADATA . $fileKey);
        if ($localPath || $metadata) {
            // Combine metadata from source with destination
            // Either an existing $metadata or $localPath should be enough
            // to generate enough data to cache
            $newMetadata = array_merge(
                $metadata,
                $this->approximateMetadata($newpath, $localPath)
            );
            $newMetadata['timestamp'] = time();
            $this->getMetadataCache()->set(self::METADATA . $newKey, $newMetadata);
        }
    }

    /**
     * Remove metadata from a file we know doesn't exist anymore
     *
     * @param string $path
     */
    public function deleteMetadata($path)
    {
        $fileKey = sha1($path);
        $this->getMetadataCache()->set(self::METADATA . $fileKey, []);
        $this->getMetadataCache()->delete(self::VISIBILITY . $fileKey);
        $this->getContentCache()->delete($fileKey);
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
}

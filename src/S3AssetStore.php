<?php

namespace SilverStripe\S3;

use League\Flysystem\Filesystem;
use SilverStripe\S3\Cache\ContentWarmer;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;

/**
 * Adds cache-warming behaviour for content set from setFromLocalFile() and setFromString()
 *
 * Note: setFromStream is intentionally not warmed since internal logic isn't deterministic
 * (cache at adapter level instead)
 */
class S3AssetStore extends FlysystemAssetStore
{
    /**
     * @var ContentWarmer
     */
    protected $contentWarmer = null;

    /**
     * Get warmer for warming local cache from input resources (e.g. streams, local files)
     *
     * @return ContentWarmer
     */
    public function getContentWarmer()
    {
        return $this->contentWarmer;
    }

    public function setContentWarmer(ContentWarmer $warmer)
    {
        $this->contentWarmer = $warmer;
        return $this;
    }

    public function setFromLocalFile($path, $filename = null, $hash = null, $variant = null, $config = array())
    {
        $result = parent::setFromLocalFile($path, $filename, $hash, $variant, $config);

        // Warm cache
        $warmer = $this->getContentWarmer();
        if ($warmer) {
            $key = $this->cacheKeyForTuple($result);
            $warmer->warmFromPath($key, $path);
        }

        return $result;
    }

    public function setFromString($data, $filename, $hash = null, $variant = null, $config = array())
    {
        $result = parent::setFromString($data, $filename, $hash, $variant, $config);

        // Warm cache
        $warmer = $this->getContentWarmer();
        if ($warmer) {
            $key = $this->cacheKeyForTuple($result);
            $warmer->warmFromString($key, $data);
        }

        return $result;
    }

    /**
     * Calculate cache key from tuple array
     *
     * @param array $tuple
     * @return string
     */
    protected function cacheKeyForTuple($tuple)
    {
        $fileID = $this->getFileID($tuple['Filename'], $tuple['Hash'], $tuple['Variant']);
        return sha1($fileID);
    }

    protected function truncateDirectory($dirname, Filesystem $filesystem)
    {
        // No-op
    }
}

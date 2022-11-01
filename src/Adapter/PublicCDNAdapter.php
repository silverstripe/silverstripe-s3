<?php

namespace SilverStripe\S3\Adapter;

use Aws\S3\S3Client;
use SilverStripe\Assets\Flysystem\PublicAdapter as SilverstripePublicAdapter;
use SilverStripe\Control\Controller;

use const ASSETS_DIR;

/**
 * Class PublicCDNAdapter
 * @package SilverStripe\S3\Adapter
 */
class PublicCDNAdapter extends PublicAdapter implements SilverstripePublicAdapter
{
    protected $cdnPrefix;

    protected $cdnAssetPath;

    public function __construct(S3Client $client, $bucket, $prefix = '', $cdnPrefix = '', $cdnAssetPath = '', array $options = [])
    {
        $this->cdnPrefix = $cdnPrefix;
        $this->cdnAssetPath = $cdnAssetPath ? $cdnAssetPath : ASSETS_DIR;
        parent::__construct($client, $bucket, $prefix, $options);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getPublicUrl($path)
    {
        return Controller::join_links($this->cdnPrefix, $this->cdnAssetPath, $path);
    }


    public function setCdnPrefix(string $cdnPrefix): self
    {
        $this->cdnPrefix = $cdnPrefix;

        return $this;
    }
}

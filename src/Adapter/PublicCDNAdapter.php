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

    protected $cdnAssetsDir;

    public function __construct(S3Client $client, $bucket, $prefix = '', $cdnPrefix = '', $cdnAssetsDir = '', array $options = [])
    {
        $this->cdnPrefix = $cdnPrefix;
        $this->cdnAssetsDir = $cdnAssetsDir ? $cdnAssetsDir : ASSETS_DIR;
        parent::__construct($client, $bucket, $prefix, $options);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getPublicUrl($path)
    {
        return Controller::join_links($this->cdnPrefix, $this->cdnAssetsDir, $path);
    }


    public function setCdnPrefix(string $cdnPrefix): self
    {
        $this->cdnPrefix = $cdnPrefix;

        return $this;
    }
}

<?php

namespace SilverStripe\S3\Adapter;

use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Assets\Flysystem\PublicAdapter;

class PublicS3Adapter extends AwsS3Adapter implements PublicAdapter
{
    public function __construct(S3Client $client, $bucket, $prefix = '', array $options = [])
    {
        if (!$bucket) {
            throw new InvalidArgumentException("AWS_BUCKET_NAME environment variable not set");
        }
        if (!$prefix) {
            $prefix = 'public';
        }
        parent::__construct($client, $bucket, $prefix, $options);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getPublicUrl($path)
    {
        return $this->getClient()->getObjectUrl($this->getBucket(), $this->applyPathPrefix($path));
    }
}

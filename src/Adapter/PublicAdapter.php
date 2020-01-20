<?php

namespace SilverStripe\S3\Adapter;

use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Assets\Flysystem\PublicAdapter as SilverstripePublicAdapter;

class PublicAdapter extends AwsS3Adapter implements SilverstripePublicAdapter
{
    public function __construct(S3Client $client, $bucket, $prefix = null, array $options = [])
    {
        if (!$bucket) {
            throw new InvalidArgumentException("AWS_BUCKET_NAME environment variable not set");
        }
        if ($prefix === null) {
            // prefix can be an empty but needs to be set (not null)
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

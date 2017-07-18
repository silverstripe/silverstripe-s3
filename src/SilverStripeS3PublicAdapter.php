<?php

namespace Madmatt\SilverStripeS3;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Assets\Flysystem\PublicAdapter;

class SilverStripeS3PublicAdapter extends AwsS3Adapter implements PublicAdapter
{
    use SilverStripeS3AdapterTrait;

    /**
     * @param S3Client $s3Client
     */
    public function __construct(S3Client $s3Client)
    {
        parent::__construct($s3Client, $this->findAwsBucket(), $this->findBucketPrefix());
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

    /**
     * @return string
     */
    protected function findBucketPrefix()
    {
        $prefix = 'public';
        if (getenv('AWS_PUBLIC_BUCKET_PREFIX') !== false) {
            $prefix = (string) getenv('AWS_PUBLIC_BUCKET_PREFIX');
        }

        return $prefix;
    }
}

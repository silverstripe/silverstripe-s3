<?php

namespace Madmatt\SilverStripeS3;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Assets\Flysystem\PublicAdapter;

class SilverStripeS3PublicAdapter extends AwsS3Adapter implements PublicAdapter
{
    use SilverStripeS3AdapterTrait;

    /**
     * @var CloudFrontClient
     */
    protected $cloudFrontClient;

    /**
     * @param S3Client         $s3Client
     * @param CloudFrontClient $cloudFrontClient
     */
    public function __construct(S3Client $s3Client, CloudFrontClient $cloudFrontClient)
    {
        $this->cloudFrontClient = $cloudFrontClient;
        parent::__construct($s3Client, $this->findAwsBucket(), $this->findBucketPrefix());
    }

    /**
     * @return CloudFrontClient
     */
    public function getCloudFrontClient()
    {
        return $this->cloudFrontClient;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getPublicUrl($path)
    {
        if (getenv('AWS_CLOUDFRONT_PUBLIC_DISTRIBUTION_URL')) {
            return $this->getDistributionUrl($path);
        }

        return $this->getBucketUrl($path);
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

    /**
     * @param string $path
     *
     * @return string
     */
    protected function getBucketUrl($path)
    {
        return $this->getClient()->getObjectUrl($this->getBucket(), $this->applyPathPrefix($path));
    }

    /**
     * @param string $path
     *
     * @return string
     */
    protected function getDistributionUrl($path)
    {
        return sprintf('%s/%s', getenv('AWS_CLOUDFRONT_PUBLIC_DISTRIBUTION_URL'), $path);
    }
}

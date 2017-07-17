<?php

namespace Madmatt\SilverStripeS3;

use Aws\CloudFront\CloudFrontClient;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Assets\Flysystem\ProtectedAdapter;

/**
 * An adapter that allows the use of AWS S3 to store and transmit assets rather than storing them locally.
 */
class SilverStripeS3ProtectedAdapter extends AwsS3Adapter implements ProtectedAdapter
{
    use SilverStripeS3AdapterTrait;

    /**
     * Pre-signed request expiration time in seconds.
     *
     * @var mixed
     */
    protected $expiry = 300;

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
     * @return mixed
     */
    public function getExpiry()
    {
        return $this->expiry;
    }

    /**
     * @param mixed $expiry
     */
    public function setExpiry($expiry)
    {
        $this->expiry = $expires;
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
    public function getProtectedUrl($path)
    {
        if (
            getenv('AWS_CLOUDFRONT_PROTECTED_DISTRIBUTION_URL') &&
            file_exists($this->findCloudFrontPrivateKeyPath())
        ) {
            return $this->getDistributionUrl($path);
        }

        return $this->getBucketUrl($path);
    }

    /**
     * @return string
     */
    protected function findBucketPrefix()
    {
        $prefix = 'protected';
        if (getenv('AWS_PROTECTED_BUCKET_PREFIX') !== false) {
            $prefix = (string) getenv('AWS_PROTECTED_BUCKET_PREFIX');
        }

        return $prefix;
    }

    /**
     * @throws LogicException
     *
     * @return string
     */
    protected function findCloudFrontPrivateKeyId()
    {
        if (getenv('AWS_CLOUDFRONT_PRIVATE_KEY_ID') !== false) {
            return (string) getenv('AWS_CLOUDFRONT_PRIVATE_KEY_ID');
        }
        throw new LogicException('AWS_CLOUDFRONT_PRIVATE_KEY_ID environment variable not set');
    }

    /**
     * @throws LogicException
     *
     * @return string
     */
    protected function findCloudFrontPrivateKeyPath()
    {
        if (getenv('AWS_CLOUDFRONT_PRIVATE_KEY_PATH') !== false) {
            return (string) getenv('AWS_CLOUDFRONT_PRIVATE_KEY_PATH');
        }
        throw new LogicException('AWS_CLOUDFRONT_PRIVATE_KEY_PATH environment variable not set');
    }

    /**
     * @param string $path
     *
     * @return string
     */
    protected function getBucketUrl($path)
    {
        $cmd = $this->getClient()
            ->getCommand('GetObject', [
                'Bucket' => $this->getBucket(),
                'Key' => $this->applyPathPrefix($path),
            ]);

        return (string) $this->getClient()
            ->createPresignedRequest($cmd, time() + $this->getExpiry())
            ->getUri();
    }

    /**
     * @param string $path
     *
     * @return string
     */
    protected function getDistributionUrl($path)
    {
        return $this->getCloudFrontClient()->getSignedUrl([
            'url' => sprintf('%s/%s', getenv('AWS_CLOUDFRONT_PROTECTED_DISTRIBUTION_URL'), $path),
            'expires' => time() + $this->getExpiry(),
            'private_key' => $this->findCloudFrontPrivateKeyPath(),
            'key_pair_id' => $this->findCloudFrontPrivateKeyId(),
        ]);
    }
}

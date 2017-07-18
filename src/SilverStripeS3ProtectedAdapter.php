<?php

namespace Madmatt\SilverStripeS3;

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
     * @param S3Client $s3Client
     */
    public function __construct(S3Client $s3Client)
    {
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
     * @param string $path
     *
     * @return string
     */
    public function getProtectedUrl($path)
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
}

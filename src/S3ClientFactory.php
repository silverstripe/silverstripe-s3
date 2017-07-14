<?php

namespace Madmatt\SilverStripeS3;

use Aws\S3\S3Client;
use LogicException;
use SilverStripe\Core\Injector\Factory;

class S3ClientFactory implements Factory
{
    /**
     * Creates a new service instance.
     *
     * @param string $service The class name of the service.
     * @param array $params The constructor parameters.
     * @return object The created service instances.
     */
    public function create($service, array $params = array())
    {
        return new S3Client($this->getConnectionArgs());
    }

    /**
     * Get AWS API Key
     *
     * @return string
     */
    public function findAwsKeyId()
    {
        if (getenv('AWS_ACCESS_KEY_ID') !== false) {
            return (string) getenv('AWS_ACCESS_KEY_ID');
        }
        return null;
    }

    /**
     * Get AWS API Secret
     *
     * @return string
     */
    public function findAwsSecretKey()
    {
        if (getenv('AWS_SECRET_ACCESS_KEY') !== false) {
            return (string) getenv('AWS_SECRET_ACCESS_KEY');
        }
        return null;
    }

    /**
     * Get aws region
     *
     * @return string
     */
    public function findAwsRegion()
    {
        if (getenv('AWS_REGION') !== false) {
            return (string) getenv('AWS_REGION');
        }
        throw new LogicException('No valid AWS region specified. Please set AWS_REGION in your env.');
    }

    /**
     * Get connection arguments
     *
     * @return array
     */
    protected function getConnectionArgs()
    {
        $args = [
            'region' => $this->findAwsRegion(),
            'version' => 'latest'
        ];
        $key = $this->findAwsKeyId();
        $secret = $this->findAwsSecretKey();
        if ($key && $secret) {
            $args['credentials'] = [
                'key' => $key,
                'secret' => $secret
            ];
        }
        return $args;
    }
}

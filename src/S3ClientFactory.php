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
    public function findAwsKey()
    {
        if (getenv('SS_AWS_S3_KEY') !== false) {
            return (string)getenv('SS_AWS_S3_KEY');
        }
        if (getenv('SS_AWS_KEY') !== false) {
            return (string)getenv('SS_AWS_KEY');
        }
        return null;
    }

    /**
     * Get AWS API Secret
     *
     * @return string
     */
    public function findAwsSecret()
    {
        if (getenv('SS_AWS_S3_SECRET') !== false) {
            return (string)getenv('SS_AWS_S3_SECRET');
        }
        if (getenv('SS_AWS_SECRET') !== false) {
            return (string)getenv('SS_AWS_SECRET');
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
        if (getenv('SS_AWS_S3_REGION') !== false) {
            return (string)getenv('SS_AWS_S3_REGION');
        }
        if (getenv('SS_AWS_REGION') !== false) {
            return (string)getenv('SS_AWS_REGION');
        }
        throw new LogicException('No valid AWS region specified. Please add SS_AWS_S3_REGION to your env.');
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
        $key = $this->findAwsKey();
        $secret = $this->findAwsSecret();
        if ($key && $secret) {
            $args['credentials'] = [
                'key' => $this->findAwsKey(),
                'secret' => $this->findAwsSecret()
            ];
        }
        return $args;
    }
}

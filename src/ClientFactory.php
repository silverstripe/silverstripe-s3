<?php

namespace Madmatt\SilverStripeS3;

use SilverStripe\Core\Injector\Factory;

class ClientFactory implements Factory
{
    /**
     * @param string $service
     * @param array  $params
     *
     * @return S3Client
     */
    public function create($service, array $params = [])
    {
        return new $service([
            'region' => getenv('AWS_REGION'),
            'version' => 'latest',
        ]);
    }
}

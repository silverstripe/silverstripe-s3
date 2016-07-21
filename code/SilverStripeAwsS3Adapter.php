<?php

namespace Madmatt\SilverStripeS3;

use \Aws\S3\S3Client;
use \League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Filesystem\Flysystem\ProtectedAdapter;
use SilverStripe\Filesystem\Flysystem\PublicAdapter;

class SilverStripeAwsS3Adapter_Public extends AwsS3Adapter implements PublicAdapter {
	use SilverStripeAwsS3Adapter_Trait;

	public function __construct() {
		$s3Client = new S3Client([
			'credentials' => [
				'key' => $this->findAwsKey(),
				'secret' => $this->findAwsSecret()
			],
			'region' => $this->findAwsRegion(),
			'version' => 'latest'
		]);

		parent::__construct($s3Client, $this->findAwsBucket(), 'public');
	}

	public function getPublicUrl($path) {
		return $this->getClient()->getObjectUrl($this->getBucket(), $this->applyPathPrefix($path));
	}
}

class SilverStripeAwsS3Adapter_Protected extends AwsS3Adapter implements ProtectedAdapter {
	use SilverStripeAwsS3Adapter_Trait;

	public function __construct() {
		$s3Client = new S3Client([
			'credentials' => [
				'key' => $this->findAwsKey(),
				'secret' => $this->findAwsSecret()
			],
			'region' => $this->findAwsRegion(),
			'version' => 'latest'
		]);

		parent::__construct($s3Client, $this->findAwsBucket(), 'protected');
	}

	public function getProtectedUrl($path) {
		return $this->getClient()->getObjectUrl($this->getBucket(), $this->applyPathPrefix($path));
	}
}

/**
 * Class SilverStripeAwsS3Adapter_Trait
 * @package Madmatt\SilverStripeS3
 *
 * Contains methods shared between both public and protected adapters, primarily to find an appropriate AWS S3
 * key/secret to use.
 */
trait SilverStripeAwsS3Adapter_Trait {
	public function findAwsKey() {
		if(defined('SS_AWS_S3_KEY') && strlen((string)SS_AWS_S3_KEY) > 0) {
			return (string)SS_AWS_S3_KEY;
		} elseif(defined('SS_AWS_KEY') && strlen((string)SS_AWS_KEY) > 0) {
			return (string)SS_AWS_KEY;
		} elseif(getenv('SS_AWS_S3_KEY') !== false) {
			return (string)getenv('SS_AWS_S3_KEY');
		} elseif(getenv('SS_AWS_KEY') !== false) {
			return (string)getenv('SS_AWS_KEY');
		} else {
			throw new \LogicException(
				'Unable to find a valid AWS key to connect to S3 using. Please either define() SS_AWS_S3_KEY, or ensure'
					. ' an environment variable named SS_AWS_S3_KEY is available.'
			);
		}
	}

	public function findAwsSecret() {
		if(defined('SS_AWS_S3_SECRET') && strlen((string)SS_AWS_S3_SECRET) > 0) {
			return (string)SS_AWS_S3_SECRET;
		} elseif(defined('SS_AWS_SECRET') && strlen((string)SS_AWS_SECRET) > 0) {
			return (string)SS_AWS_SECRET;
		} elseif(getenv('SS_AWS_S3_SECRET') !== false) {
			return (string)getenv('SS_AWS_S3_SECRET');
		} elseif(getenv('SS_AWS_SECRET') !== false) {
			return (string)getenv('SS_AWS_SECRET');
		} else {
			throw new \LogicException(
				'Unable to find a valid AWS secret to connect to S3 using. Please either define() SS_AWS_S3_SECRET, or'
					. ' ensure an environment variable named SS_AWS_S3_SECRET is available.'
			);
		}
	}

	public function findAwsRegion() {
		if(defined('SS_AWS_S3_REGION') && strlen((string)SS_AWS_S3_REGION) > 0) {
			return (string)SS_AWS_S3_REGION;
		} elseif(defined('SS_AWS_REGION') && strlen((string)SS_AWS_REGION) > 0) {
			return (string)SS_AWS_REGION;
		} elseif(getenv('SS_AWS_S3_REGION') !== false) {
			return (string)getenv('SS_AWS_S3_REGION');
		} elseif(getenv('SS_AWS_REGION') !== false) {
			return (string)getenv('SS_AWS_REGION');
		} else {
			throw new \LogicException(
				'Unable to find a valid AWS region to connect to S3 using. Please either define() SS_AWS_S3_REGION, or'
					. ' ensure an environment variable named SS_AWS_S3_REGION is available. Ensure the region name is'
					. ' valid (e.g. ap-southeast-2)'
			);
		}
	}

	public function findAwsBucket() {
		if(defined('SS_AWS_S3_BUCKET_NAME') && strlen((string)SS_AWS_S3_BUCKET_NAME) > 0) {
			return (string)SS_AWS_S3_BUCKET_NAME;
		} elseif(defined('SS_AWS_BUCKET_NAME') && strlen((string)SS_AWS_BUCKET_NAME) > 0) {
			return (string)SS_AWS_BUCKET_NAME;
		} elseif(getenv('SS_AWS_S3_BUCKET_NAME') !== false) {
			return (string)getenv('SS_AWS_S3_BUCKET_NAME');
		} elseif(getenv('SS_AWS_BUCKET_NAME') !== false) {
			return (string)getenv('SS_AWS_BUCKET_NAME');
		} else {
			throw new \LogicException(
				'Unable to find a valid AWS S3 bucket to connect to. Please either define() SS_AWS_S3_BUCKET_NAME or'
					. ' ensure an environment variable named SS_AWS_S3_BUCKET_NAME is available.'
			);
		}
	}
}

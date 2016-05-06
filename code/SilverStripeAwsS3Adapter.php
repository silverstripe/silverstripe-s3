<?php

namespace SilverStripeS3;

use \League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Filesystem\Flysystem\ProtectedAdapter;
use SilverStripe\Filesystem\Flysystem\PublicAdapter;

class SilverStripeAwsS3Adapter_Public extends AwsS3Adapter implements PublicAdapter {
	public function __construct() {
		$s3Client = new \Aws\S3\S3Client([
			'credentials' => [
				'key' => 'XXX',
				'secret' => 'XXX'
			],
			'region' => 'ap-southeast-2',
			'version' => 'latest'
		]);

		parent::__construct($s3Client, 'ss4-test', 'public');
	}

	public function getPublicUrl($path) {
		return $this->getClient()->getObjectUrl($this->getBucket(), $this->applyPathPrefix($path));
	}
}

class SilverStripeAwsS3Adapter_Protected extends AwsS3Adapter implements ProtectedAdapter {
	public function __construct() {
		$s3Client = new \Aws\S3\S3Client([
			'credentials' => [
				'key' => 'XXX',
				'secret' => 'XXX'
			],
			'region' => 'ap-southeast-2',
			'version' => 'latest'
		]);

		parent::__construct($s3Client, 'ss4-test', 'protected');
	}

	public function getProtectedUrl($path) {
		return $this->getClient()->getObjectUrl($this->getBucket(), $this->applyPathPrefix($path));
	}
}

<?php

namespace SilverStripe\S3\Tests;

use SilverStripe\Control\Director;
use SilverStripe\Core\TempFolder;

trait S3TestHelperTrait
{
    protected $tempFiles = [];

    protected function tearDownFiles()
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Create temp file path and return it. This file will be cleaned up on tearDown()
     *
     * @param string $content
     * @return string
     */
    protected function createTempFile($content = 'some test content')
    {
        $tempFolder = TempFolder::getTempFolder(Director::baseFolder());
        $tempFile = tempnam($tempFolder, 'contentcachetest');
        file_put_contents($tempFile, $content);
        $this->tempFiles[] = $tempFile;
        return $tempFile;
    }
}

<?php

namespace Silverstripe\S3\Tasks;

use SilverStripe\Dev\BuildTask;

class S3DebugAsset extends BuildTask
{
    protected $title = 'S3 Debug Asset';

    private static $segment = 'S3DebugAsset';

    protected $description = 'Debug S3 Asset';

    public function run($request)
    {
        $fileId = $request->getVar('fileId');

        if (!$fileId) {
            echo 'Please provide fileId';
            return;
        }

        $file = \SilverStripe\Assets\File::get()->byId($fileId);

        if (!$file) {
            echo 'File not found';
            return;
        }

        var_dump($file->getAbsoluteURL());
    }
}

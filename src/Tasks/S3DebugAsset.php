<?php

namespace Silverstripe\S3\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

class S3DebugAsset extends BuildTask
{
    protected string $title = 'S3 Debug Asset';

    protected static string $commandName = 'S3DebugAsset';

    protected static string $description = 'Debug S3 Asset';

    protected function execute(InputInterface $input, PolyOutput $output) :int
    {
        $fileId = $request->getVar('fileId');

        if (!$fileId) {
            echo 'Please provide fileId';
            return Command::INVALID;
        }

        $file = \SilverStripe\Assets\File::get()->byId($fileId);

        if (!$file) {
            echo 'File not found';
            return Command::FAILURE;
        }

        $output->writeln($file->getAbsoluteURL());
        return Command::SUCCESS;
    }
}

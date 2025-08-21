<?php

namespace Silverstripe\S3\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

class S3DebugAsset extends BuildTask
{
    protected string $title = 'S3 Debug Asset';

    protected static string $commandName = 'S3DebugAsset';

    protected static string $description = 'Debug S3 Asset';

    protected function execute(InputInterface $input, PolyOutput $output) :int
    {
        $fileId = $input->getOption('fileId');

        if (!$fileId) {
            echo 'Please provide fileId';
            return Command::INVALID;
        }

        $file = \SilverStripe\Assets\File::get()->byID($fileId);

        if (!$file) {
            echo 'File not found';
            return Command::FAILURE;
        }

        $output->writeln($file->getAbsoluteURL());
        return Command::SUCCESS;
    }

    public function getOptions(): array
    {
        return [
            new InputOption('fileId', null, InputOption::VALUE_REQUIRED, 'provide the file ID to test grabbing the files URL'),
        ];
    }
}

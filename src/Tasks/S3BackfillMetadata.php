<?php

namespace SilverStripe\S3\Tasks;

use League\Flysystem\Filesystem;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\S3\Adapter\CachedAwsS3V3Adapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

class S3BackfillMetadata extends BuildTask
{
    protected string $title = 'S3 Backfill Metadata';

    protected static string $commandName = 'S3BackfillMetadata';

    protected static string $description = 'Add hash and image dimension metadata to S3 objects'
        . ' which were uploaded before the module stored it';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $path = trim((string) $input->getOption('path'), '/');
        $limit = (int) $input->getOption('limit');

        $updated = $skipped = $failed = 0;

        foreach ([AssetStore::VISIBILITY_PUBLIC, AssetStore::VISIBILITY_PROTECTED] as $store) {
            $adapter = Injector::inst()->get(Filesystem::class . '.' . $store)->getAdapter();

            if (!$adapter instanceof CachedAwsS3V3Adapter) {
                $output->writeln("Skipping the {$store} store as it isn't on S3");
                continue;
            }

            foreach ($adapter->listContents($path, true) as $item) {
                if (!$item->isFile()) {
                    continue;
                }

                if ($limit && $updated >= $limit) {
                    break 2;
                }

                try {
                    $metadata = $adapter->backfillObjectMetadata($item->path(), $force, $dryRun);
                } catch (Throwable $e) {
                    $failed++;
                    $output->writeln("{$store}: {$item->path()} FAILED: {$e->getMessage()}");
                    continue;
                }

                if ($metadata === null) {
                    $skipped++;
                    continue;
                }

                $updated++;
                $output->writeln("{$store}: {$item->path()} " . json_encode($metadata));
            }
        }

        $output->writeln(sprintf(
            '%s %d, already had metadata %d, failed %d',
            $dryRun ? 'Would update' : 'Updated',
            $updated,
            $skipped,
            $failed
        ));

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    public function getOptions(): array
    {
        return [
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be written, without changing anything'),
            new InputOption('force', null, InputOption::VALUE_NONE, 'Recalculate metadata for objects which already have it'),
            new InputOption('path', null, InputOption::VALUE_REQUIRED, 'Only process objects under this folder', ''),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after updating this many objects', 0),
        ];
    }
}

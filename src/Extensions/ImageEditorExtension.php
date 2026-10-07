<?php

namespace SilverStripe\S3\Extensions;

use SilverStripe\AssetAdmin\Model\ImageEditor;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetNameGenerator;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * The image editor replaces an image's bytes under its existing filename. Behind a CDN the public
 * URL would not change, so the cached pre-edit image would keep being served. This gives the edited
 * image a new versioned filename (bird.jpg becomes bird-v2.jpg) so it is added to the bucket under
 * a new URL instead. Where a backup is taken, the backup keeps the original filename and uses the
 * original asset where it is, rather than a copy of it.
 *
 * Only published images are affected, as no other image has a public URL to be cached.
 *
 * @extends Extension<ImageEditor>
 */
class ImageEditorExtension extends Extension
{
    protected function onBeforeCreateBackup(Image $backup, Image $source): void
    {
        if (!$source->isPublished()) {
            return;
        }
        // Free the original name up in both stages, so File::onBeforeWrite() does not de-duplicate
        // the backup when it is written or published. Only the name is changed, as the published
        // image must keep using the original asset until the edit is published.
        $name = $this->getNextName($source);
        foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
            $table = $source->stageTable($source->baseTable(), $stage);
            DB::prepared_query("UPDATE \"$table\" SET \"Name\" = ? WHERE \"ID\" = ?", [$name, $source->ID]);
        }
    }

    protected function onBeforeReplaceOriginal(Image $source, array $transforms): void
    {
        if (!$source->isPublished()) {
            return;
        }
        $source->Name = $this->getNextName($source);
        // Move the rendered asset, which is not yet referenced by any written record
        $source->File->renameFile($source->generateFilename());
    }

    /**
     * The next versioned name which is not used by another file in the same folder
     */
    private function getNextName(Image $source): string
    {
        $generator = Injector::inst()->createWithArgs(AssetNameGenerator::class, [$source->Name]);
        foreach ($generator as $name) {
            if ($name === $source->Name) {
                continue;
            }
            $siblings = File::get()
                ->filter(['Name' => $name, 'ParentID' => (int) $source->ParentID])
                ->exclude('ID', $source->ID);
            if (!$siblings->exists()) {
                return $name;
            }
        }
        return $source->Name;
    }
}

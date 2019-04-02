<?php

namespace SilverStripe\S3\Adapter;

use League\Flysystem\Cached\CachedAdapter;
use SilverStripe\Assets\Flysystem\PublicAdapter as SilverstripePublicAdapter;

class PublicCachedAdapter extends CachedAdapter implements SilverstripePublicAdapter
{
    public function getPublicUrl($path)
    {
        return $this->getAdapter()->getPublicUrl($path);
    }
}

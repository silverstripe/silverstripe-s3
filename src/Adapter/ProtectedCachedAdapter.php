<?php

namespace SilverStripe\S3\Adapter;

use League\Flysystem\Cached\CachedAdapter;
use SilverStripe\Assets\Flysystem\ProtectedAdapter as SilverstripeProtectedAdapter;

class ProtectedCachedAdapter extends CachedAdapter implements SilverstripeProtectedAdapter
{
    public function getProtectedUrl($path)
    {
        return $this->getAdapter()->getProtectedUrl($path);
    }
}

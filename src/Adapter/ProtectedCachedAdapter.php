<?php

namespace SilverStripe\S3\Adapter;

use League\Flysystem\Cached\CachedAdapter;
use SilverStripe\Assets\Flysystem\ProtectedAdapter;

class ProtectedCachedAdapter extends CachedAdapter implements ProtectedAdapter
{
    public function getProtectedUrl($path)
    {
        return $this->getAdapter()->getProtectedUrl($path);
    }
}
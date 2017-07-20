<?php

namespace SilverStripe\S3\Adapter;

use BadMethodCallException;
use League\Flysystem\AdapterInterface;
use SilverStripe\Assets\Flysystem\ProtectedAdapter;

class ProtectedCacheAdapter extends CacheAdapter implements ProtectedAdapter
{
    /**
     * Get backend protected adapter
     *
     * @return ProtectedAdapter
     */
    public function getBackend()
    {
        /** @var ProtectedAdapter $backend */
        $backend = parent::getBackend();
        return $backend;
    }

    public function setBackend(AdapterInterface $backend)
    {
        if (! $backend instanceof ProtectedAdapter) {
            throw new BadMethodCallException("Can't cache non-protected adapter");
        }
        return parent::setBackend($backend);
    }


    public function getProtectedUrl($path)
    {
        return $this->getBackend()->getProtectedUrl($path);
    }
}

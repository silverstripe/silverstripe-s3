<?php

namespace SilverStripe\S3\Adapter;

use BadMethodCallException;
use League\Flysystem\AdapterInterface;
use SilverStripe\Assets\Flysystem\PublicAdapter;

class PublicCacheAdapter extends CacheAdapter implements PublicAdapter
{
    /**
     * Get backend protected adapter
     *
     * @return PublicAdapter
     */
    public function getBackend()
    {
        /** @var PublicAdapter $backend */
        $backend = parent::getBackend();
        return $backend;
    }

    public function setBackend(AdapterInterface $backend)
    {
        if (!$backend instanceof PublicAdapter) {
            throw new BadMethodCallException("Can't cache non-public adapter");
        }
        return parent::setBackend($backend);
    }

    public function getPublicUrl($path)
    {
        return $this->getBackend()->getPublicUrl($path);
    }
}

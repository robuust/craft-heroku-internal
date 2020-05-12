<?php

namespace robuust\heroku\volumes;

use craft\awss3\Volume;

/**
 * Local volume.
 */
class Local extends \craft\volumes\Local
{
    /**
     * {@inheritdoc}
     */
    public function __set($name, $value)
    {
        $theirProperties = get_class_vars(Volume::class);
        $ownProperties = get_class_vars(static::class);
        $properties = array_diff_assoc($theirProperties, $ownProperties);

        if (!in_array($name, $properties)) {
            parent::__set($name, $value);
        }
    }
}

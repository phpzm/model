<?php

namespace Simples\Model\Resources;

use Simples\Helper\Date;
use Simples\Security\Auth;

/**
 * Class Timestamp
 * @package Simples\Model\Resources
 */
trait Timestamp
{
    /**
     * @param string $type
     * @return null|string
     */
    protected function getTimestampValue(string $type)
    {
        switch ($type) {
            case 'at':
                return Date::now();
                break;
            case 'by':
                return Auth::getUser();
                break;
        }
        return null;
    }
}

<?php

namespace Simples\Model\Resource;

use Simples\Helper\Date;
use Simples\Persistence\Field;
use Simples\Security\Auth;

/**
 * Class Timestamp
 * @package Simples\Model\Resources
 */
trait ModelTimestamp
{
    /**
     * @param string $type
     * @return null|string
     */
    protected function getTimestampType(string $type)
    {
        switch ($type) {
            case 'at':
                return Field::TYPE_DATETIME;
                break;
            case 'by':
                return Field::TYPE_STRING;
                break;
        }
        return null;
    }

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
                return Auth::get($this->by, $this->anonymous);
                break;
        }
        return null;
    }
}

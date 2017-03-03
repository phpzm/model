<?php

namespace Simples\Model\Error;

use Simples\Error\SimplesRunTimeError;

/**
 * Class SimplesHookError
 * @package Simples\Model\Error
 */
class SimplesHookError extends SimplesRunTimeError
{
    /**
     * SimplesHookError constructor.
     * @param string $class
     * @param string $action
     * @param string $hook
     */
    public function __construct(string $class, string $action, string $hook)
    {
        parent::__construct("Can't resolve hook `{$action}`.`{$hook}` in '" . $class . "'");
    }
}

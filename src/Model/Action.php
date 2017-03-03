<?php

namespace Simples\Model;

/**
 * Class Action
 * @package Simples\Model
 */
abstract class Action
{
    /**
     * @var string
     */
    const
        CREATE = 'create', READ = 'read', UPDATE = 'update', DESTROY = 'destroy', RECOVER = 'recover';
}

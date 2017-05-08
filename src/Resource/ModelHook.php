<?php

namespace Simples\Model\Resource;

use Simples\Data\Record;
use Simples\Kernel\Container;

/**
 * Class ModelHook
 * @package Simples\Model\Resource
 */
trait ModelHook
{
    /**
     * @param string $collection
     * @param string $method
     * @param bool $strict
     * @return array
     */
    final public function filterFields(string $collection, string $method, bool $strict)
    {
        return array_filter($this->fields, function ($field) use ($collection, $method, $strict) {
            if ($strict && $field->getCollection() !== $collection) {
                return null;
            }
            if (!$method) {
                return $field;
            }
            if ($method && $field->$method()) {
                return $field;
            }
            return null;
        });
    }

    /**
     * @param string $action
     * @param Record $record
     */
    final public function configureFields(string $action, Record $record)
    {
        foreach ($this->maps as $source => $target) {
            $record->set($target, $record->get($source));
        }
        $action = ucfirst($action);
        if (method_exists($this, "configureFields{$action}")) {
            call_user_func_array([$this, "configureFields{$action}"], [$record]);
        }
    }

    /**
     * This method is called before the operation be executed, the changes made in Record will be save
     * @param string $action
     * @param Record $record
     * @param Record $previous
     * @return bool
     */
    final public function before(string $action, Record $record, Record $previous = null): bool
    {
        $action = ucfirst($action);
        $method = "before{$action}";
        if (method_exists($this, $method)) {
            $data = ['record' => $record, 'previous' => $previous];
            $parameters = Container::instance()->resolveMethodParameters($this, $method, $data, true);
            return call_user_func_array([$this, $method], $parameters);
        }
        return true;
    }

    /**
     * Triggered after operation be executed, the changes made in Record has no effect in storage
     * @param string $action
     * @param Record $record
     * @return bool
     */
    final public function after(string $action, Record $record): bool
    {
        $action = ucfirst($action);
        $method = "after{$action}";
        if (method_exists($this, $method)) {
            $data = ['record' => $record];
            $parameters = Container::instance()->resolveMethodParameters($this, $method, $data, true);
            return call_user_func_array([$this, $method], $parameters);
        }
        return true;
    }

    /**
     * @param string $action
     * @param Record $record
     * @return array
     */
    final public function getDefaults(string $action, Record $record = null): array
    {
        $action = ucfirst($action);
        if (method_exists($this, "getDefaults{$action}")) {
            return call_user_func_array([$this, "getDefaults{$action}"], [$record]);
        }
        return [];
    }
}

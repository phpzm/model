<?php

namespace Simples\Model\Resource;

use Simples\Data\Record;
use Simples\Error\SimplesRunTimeError;
use Simples\Kernel\Container;
use Simples\Model\Action;
use function count;

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

    /**
     * This method is called before the operation be executed, the changes made in Record will be save
     * @param string $action
     * @param Record $record
     * @param Record $previous
     * @return bool
     * @throws SimplesRunTimeError
     */
    final public function before(string $action, Record $record, Record $previous = null): bool
    {
        $action = ucfirst($action);
        $method = "before{$action}";
        $container = Container::instance();
        if ($container->exists($this, $method)) {
            return $container->invoke($this, $method, ['record' => $record, 'previous' => $previous]);
        }
        return true;
    }

    /**
     * Triggered after operation be executed, the changes made in Record has no effect in storage
     * @param string $action
     * @param Record $record
     * @param mixed $extra
     * @return bool|array
     */
    final public function after(string $action, Record $record, $extra = true)
    {
        $data = is_array($extra) ? $extra : [];

        $data = $this->afterDefault($action, $record, $data);

        $name = ucfirst($action);
        $method = "after{$name}";
        $container = Container::instance();
        if ($container->exists($this, $method)) {
            return $container->invoke($this, $method, ['record' => $record, 'data' => $data]);
        }

        if ($action === Action::READ) {
            return $data;
        }

        return $extra;
    }

    /**
     * @param string $action
     * @param Record $record
     * @param array $data
     * @return array|bool
     */
    final protected function afterDefault(string $action, Record $record, array $data = [])
    {
        $action = ucfirst($action);
        $method = "afterDefault{$action}";
        if (!method_exists($this, $method)) {
            return $data;
        }
        return call_user_func_array([$this, $method], [$record, $data]);
    }

    /**
     * @param Record $record
     * @return boolean
     * @SuppressWarnings("Unused")
     */
    final protected function afterDefaultCreate(Record $record)
    {
        $this->pivotSolver('create', $record);

        return true;
    }

    /**
     * @param Record $record
     * @param array $data
     * @return mixed
     * @SuppressWarnings("Unused")
     */
    final protected function afterDefaultRead(Record $record, array $data)
    {
        if (count($data) === 1) {
            return $this->pivotSolver('read', $record, $data);
        }
        return $data;
    }

    /**
     * @param Record $record
     * @return boolean
     * @SuppressWarnings("Unused")
     */
    final protected function afterDefaultUpdate(Record $record)
    {
        $this->pivotSolver('update', $record);

        return true;
    }

    /**
     * @param Record $record
     * @return boolean
     * @SuppressWarnings("Unused")
     */
    final protected function afterDefaultDestroy(Record $record)
    {
        $this->pivotSolver('destroy', $record);

        return true;
    }
}

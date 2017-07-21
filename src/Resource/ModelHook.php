<?php

namespace Simples\Model\Resource;

use Simples\Data\Record;
use Simples\Error\SimplesRunTimeError;
use Simples\Kernel\Container;
use Simples\Model\Action;
use Simples\Model\ModelAbstract;

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
        if (method_exists($this, $method)) {
            return !!Container::instance()->execute($this, $method, ['record' => $record, 'previous' => $previous]);
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
        if (method_exists($this, $method)) {
            return Container::instance()->execute($this, $method, ['record' => $record, 'data' => $data]);
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
        if (method_exists($this, $method)) {
            return call_user_func_array([$this, $method], [$record, $data]);
        }
        return $data;
    }

    /**
     * @param Record $record
     * @param array $data
     * @return mixed
     * @SuppressWarnings("Unused")
     */
    final protected function afterDefaultCreate(Record $record, array $data)
    {
        if (!count($this->relationships)) {
            return true;
        }

        foreach ($this->relationships as $relationship) {
            if ($relationship['type'] === 'pivot') {
                $this->pivotSynchronize(
                    $record,
                    $relationship['local'],
                    $relationship['relationship'],
                    $relationship['source'],
                    $relationship['target']
                );
            }
        }
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
        if (!count($this->relationships)) {
            return $data;
        }

        foreach ($this->relationships as $relationship) {
            if ($relationship['type'] === 'pivot') {
                $data = $this->pivotRecover(
                    $data, $relationship['local'], $relationship['relationship'], $relationship['source']
                );
            }
        }
        return $data;
    }

    /**
     * @param Record $record
     * @param array $data
     * @return mixed
     * @SuppressWarnings("Unused")
     */
    final protected function afterDefaultUpdate(Record $record, array $data)
    {
        if (!count($this->relationships)) {
            return true;
        }

        foreach ($this->relationships as $relationship) {
            if ($relationship['type'] === 'pivot') {
                $this->pivotSynchronize(
                    $record,
                    $relationship['local'],
                    $relationship['relationship'],
                    $relationship['source'],
                    $relationship['target']
                );
            }
        }

        return true;
    }

    /**
     * @param array $referenced
     * @param string $relationship
     * @return mixed
     */
    final protected function pivotModel(array $referenced, string $relationship)
    {
        $class = $referenced[$relationship]['class'];

        $container = Container::instance();
        if (!$container->has($class)) {
            $container->register($class, new $class);
        }
        /** @var ModelAbstract $model */
        return $container->get($class);
    }

    /**
     * @param array $data
     * @param string $local
     * @param string $relationship
     * @param string $source
     * @return array
     */
    final protected function pivotRecover(array $data, string $local, string $relationship, string $source): array
    {
        $referenced = $this->get($local)->getReferenced();
        if (!isset($referenced[$relationship])) {
            return $data;
        }

        $model = $this->pivotModel($referenced, $relationship);

        foreach ($data as $key => $datum) {
            $filter = [$relationship => $datum[$local]];
            $alias = null;
            $trash = false;
            $clean = true;
            $data[$key][$source] = $model->read($filter, $alias, $trash, $clean)->all();
        }
        return $data;
    }

    /**
     * @param Record $record
     * @param string $local
     * @param string $relationship
     * @param string $source
     * @param string $target
     * @return mixed
     */
    final protected function pivotSynchronize(
        Record $record,
        string $local,
        string $relationship,
        string $source,
        string $target
    ) {
        $referenced = $this->get($local)->getReferenced();
        if (!isset($referenced[$relationship])) {
            return $record;
        }

        $model = $this->pivotModel($referenced, $relationship);

        $filter = [$relationship => $record->get($local)];
        $alias = null;
        $trash = false;
        $clean = true;

        $beforeKeys = [];
        $before = $model->read($filter, $alias, $trash, $clean)->all();
        foreach ($before as $item) {
            $beforeKeys[] = off($item, $target);
        }

        $afterKeys = [];
        $after = $record->get($source);
        foreach ($after as $item) {
            $afterKeys[] = off($item, $target);
        }

        $remove = array_diff($beforeKeys, $afterKeys);
        $create = array_diff($afterKeys, $beforeKeys);

        if (empty($remove) && empty($create)) {
            return true;
        }

        $alias = null;
        $trash = false;
        $clean = true;
        foreach ($remove as $value) {
            if (!$value) {
                continue;
            }
            $item = [
                $relationship => $record->get($local),
                $target => $value
            ];
            $before = $model->read(Record::make($item), $alias, $trash, $clean);

            $model->destroy($before->current());
        }

        foreach ($create as $value) {
            $item = [
                $relationship => $record->get($local),
                $target => $value
            ];
            $model->create(Record::make($item));
        }

        return true;
    }
}

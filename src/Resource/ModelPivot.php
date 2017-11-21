<?php

namespace Simples\Model\Resource;

use Simples\Data\Record;
use Simples\Kernel\Container;
use Simples\Model\Action;
use Simples\Model\ModelAbstract;
use function count;
use function is_array;

/**
 * Trait Pivot
 * @package Simples\Model\Resource
 */
trait ModelPivot
{
    /**
     * @param string $action
     * @param Record $record
     * @param array $data
     * @return mixed
     */
    final protected function pivotSolver(string $action, Record $record, array $data = [])
    {
        if (in_array($action, [Action::CREATE, Action::UPDATE])) {
            $this->saveRelationships($action, $record);
            return null;
        }

        if (in_array($action, [Action::READ])) {
            return $this->recoverRelationships($data);
        }

        if (in_array($action, [Action::DESTROY])) {
            // return $this->destroyRelationships($record);
        }
        return null;
    }

    /**
     * @param string $action
     * @param Record $record
     * @return void
     */
    final protected function saveRelationships(string $action, Record $record)
    {
        if (!count($this->relationships)) {
            return null;
        }

        foreach ($this->relationships as $relationship) {
            $operations = $relationship['operations'];
            if (!is_array($operations)) {
                $operations = [$operations];
            }
            if (!count($operations)) {
                continue;
            }
            if (!in_array($action, $operations) && !in_array('*', $operations)) {
                continue;
            }
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
    }

    /**
     * @param array $data
     * @return array
     */
    final protected function recoverRelationships(array $data)
    {
        if (!count($this->relationships)) {
            return $data;
        }
        foreach ($this->relationships as $relationship) {
            $operations = $relationship['operations'];
            if (!is_array($operations)) {
                $operations = [$operations];
            }
            if (!count($operations)) {
                continue;
            }
            if (!in_array('read', $operations) && !in_array('*', $operations)) {
                continue;
            }
            if ($relationship['type'] === 'pivot') {
                $data = $this->pivotRecover(
                    $data, $relationship['local'], $relationship['relationship'], $relationship['source']
                );
            }
        }
        return $data;
    }

    /**
     * @param array $referenced
     * @param string $relationship
     * @return ModelAbstract
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
            if (!isset($datum[$local])) {
                continue;
            }
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

        $before = $model->read($filter, $alias, $trash, $clean)->all();
        $after = $record->get($source);

        if (! is_array($before) || !is_array($after)) {
            return false;
        }

        $beforeKeys = [];
        foreach ($before as $item) {
            $beforeKeys[] = off($item, $target);
        }

        $afterKeys = [];
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
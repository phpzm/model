<?php

namespace Simples\Model;

use Simples\Data\Collection;
use Simples\Data\Error\SimplesResourceError;
use Simples\Data\Record;
use Simples\Model\Error\SimplesActionError;
use Simples\Model\Error\SimplesHookError;
use Simples\Model\Resources\ModelParser;
use Simples\Model\Resources\Timestamp;
use Simples\Persistence\Field;
use Simples\Persistence\Filter;

/**
 * Class DataMapper
 * @package Simples\Model
 */
class DataMapper extends AbstractModel
{
    use Timestamp, ModelParser;

    /**
     * Method with the responsibility of create a record of model
     * @param array|Record $record (null)
     * @return Record
     * @throws SimplesHookError
     */
    final public function create($record = null): Record
    {
        $record = Record::parse($record);

        foreach ($this->getParents() as $relationship => $parent) {
            /** @var DataMapper $parent */
            $create = $parent->create($record);
            $record->set($relationship, $create->get($parent->getPrimaryKey()));
            $record->import($create->all());
        }

        $action = Action::CREATE;

        if (!$this->before($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'before');
        }
        if (!$record->get($this->hashKey)) {
            $record->set($this->hashKey, $this->hashKey());
        }

        $create = $this->configureRecord($action, $record);
        $fields = $create->keys();
        $values = $create->values();

        foreach ($this->createKeys as $type => $timestampsKey) {
            $fields[] = $timestampsKey;
            $values[] = $this->getTimestampValue($type);
        }

        $created = $this
            ->source($this->getCollection())
            ->fields($fields)
            ->register($values);

        $this->reset();

        if ($this->getPrimaryKey()) {
            $record->set($this->getPrimaryKey(), $created);
        }

        if (!$this->after($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'after');
        }
        return $record;
    }

    /**
     * Read records with the filters informed
     * @param array|Record $record (null)
     * @return Collection
     * @throws SimplesHookError
     */
    final public function read($record = null): Collection
    {
        $record = Record::parse(of($record, []));

        $action = Action::READ;

        if (!$this->before($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'before');
        }

        $where = [];
        $filters = [];
        if (!$record->isEmpty()) {
            $where = $this->parseFilterFields($record->all());
            $filters = $this->parseFilterValues($where);
        }

        if ($this->destroyKeys) {
            $where[] = $this->getDestroyFilter($this->destroyKeys['at']);
        }

        $array = $this
            ->source($this->getCollection())
            ->relation($this->parseReadRelations($this->fields))
            ->fields($this->getActionFields($action, false))
            ->filter($where)// TODO: needs review
            ->recover($filters);

        $this->reset();

        $record = Record::make($array);
        if (!$this->after($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'after');
        }
        return Collection::make($record->all());
    }

    /**
     * Update the record given
     * @param array|Record $record (null)
     * @return Record
     * @throws SimplesActionError
     * @throws SimplesHookError
     * @throws SimplesResourceError
     */
    final public function update($record = null): Record
    {
        $record = Record::parse($record);

        foreach ($this->getParents() as $parent) {
            /** @var DataMapper $parent */
            $record->import($parent->update($record)->all());
        }

        $action = Action::UPDATE;

        $previous = $this->previous($record, $this->hashKey);

        if ($previous->isEmpty()) {
            throw new SimplesResourceError([$this->getHashKey() => $record->get($this->getHashKey())]);
        }

        if (!$this->before($action, $record, $previous)) {
            throw new SimplesHookError(get_class($this), $action, 'before');
        }

        $record->setPrivate($this->getHashKey());

        $update = $this->configureRecord($action, $record);
        $fields = $update->keys();
        $values = $update->values();

        foreach ($this->updateKeys as $type => $timestampsKey) {
            $fields[] = $timestampsKey;
            $values[] = $this->getTimestampValue($type);
        }

        $filter = new Filter($this->get($this->getPrimaryKey()), $record->get($this->getPrimaryKey()));

        $updated = $this
            ->source($this->getCollection())
            ->fields($fields)
            ->filter([$filter])// TODO: needs review
            ->change($values, [$filter->getValue()]);

        $this->reset();

        if (!$updated) {
            throw new SimplesActionError(get_class($this), $action);
        }
        $record->setPublic($this->getHashKey());
        $record = $previous->merge($record->all());

        if (!$this->after($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'after');
        }
        return $record;
    }

    /**
     * Remove the given record of database
     * @param array|Record $record (null)
     * @return Record
     * @throws SimplesActionError
     * @throws SimplesHookError
     * @throws SimplesResourceError
     */
    final public function destroy($record = null): Record
    {
        $record = Record::parse($record);

        foreach ($this->getParents() as $parent) {
            /** @var DataMapper $parent */
            $record->import($parent->destroy($record)->all());
        }

        $action = Action::DESTROY;

        $previous = $this->previous($record, $this->hashKey);

        if ($previous->isEmpty()) {
            throw new SimplesResourceError([$this->getHashKey() => $record->get($this->getHashKey())]);
        }

        if (!$this->before($action, $record, $previous)) {
            throw new SimplesHookError(get_class($this), $action, 'before');
        }

        $filter = new Filter($this->get($this->getPrimaryKey()), $record->get($this->getPrimaryKey()));
        $filters = [$filter];

        if ($this->destroyKeys) {
            $fields = [];
            $values = [];
            foreach ($this->destroyKeys as $type => $deletedKey) {
                $fields[] = $deletedKey;
                $values[] = $this->getTimestampValue($type);
            }

            $removed = $this
                ->source($this->getCollection())
                ->fields($fields)
                ->filter($filters)// TODO: needs review
                ->change($values, [$filter->getValue()]);
        }

        if (!isset($removed)) {
            $removed = $this
                ->source($this->getCollection())
                ->filter($filters)// TODO: needs review
                ->remove([$record->get($this->getPrimaryKey())]);
        }

        $this->reset();

        if (!$removed) {
            throw new SimplesActionError(get_class($this), $action);
        }
        $record = $previous->merge($record->all());

        if (!$this->after($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'after');
        }
        return $record;
    }

    /**
     * Get total of records based on filters
     * @param array|Record $record (null)
     * @return int
     * @throws SimplesActionError
     */
    public function count($record = null): int
    {
        // Record
        $alias = 'count';
        $count = $this
            ->fields([
                new Field($this->getCollection(), $this->getPrimaryKey(), Field::AGGREGATOR_COUNT, ['alias' => $alias])
            ])
            ->limit(null)
            ->read($record)->current();

        $this->reset();

        if (!$count->has($alias)) {
            throw new SimplesActionError(get_class($this), $alias);
        }

        return (int)$count->get($alias);
    }

    /**
     * @param string $action
     * @param Record $record
     * @return Record
     */
    private function configureRecord(string $action, Record $record): Record
    {
        $values = Record::make([]);
        $fields = $this->getActionFields($action);
        foreach ($fields as $field) {
            /** @var Field $field */
            $name = $field->getName();
            if ($record->has($name)) {
                $value = $record->get($name);
            }
            if ($field->isCalculated()) {
                $value = $field->calculate($record);
                $record->set($name, $value);
            }
            if (isset($value)) {
                $values->set($name, $value);
                unset($value);
            }
        }
        return $values;
    }

    /**
     * @SuppressWarnings("BooleanArgumentFlag");
     *
     * @param string $action
     * @param bool $strict
     * @return array|mixed
     */
    protected function getActionFields(string $action, bool $strict = true)
    {
        if (off($this->getClausules(), 'fields')) {
            $fields = off($this->getClausules(), 'fields');
            if (!is_array($fields)) {
                $fields = [$fields];
            }
            $this->fields(null);
        }
        if (!isset($fields)) {
            $fields = $this->getFields($action, $strict);
        }
        return $fields;
    }
}

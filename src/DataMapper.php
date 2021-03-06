<?php

namespace Simples\Model;

use function array_merge;
use Exception;
use Simples\Data\Collection;
use Simples\Data\Error\SimplesRecordReadonlyError;
use Simples\Data\Error\SimplesResourceError;
use Simples\Data\Error\SimplesValidationError;
use Simples\Data\Record;
use Simples\Error\SimplesRunTimeError;
use Simples\Model\Error\SimplesActionError;
use Simples\Model\Error\SimplesHookError;
use Simples\Persistence\Field;
use Simples\Persistence\Filter;
use function is_array;

/**
 * Class DataMapper
 * @package Simples\Model
 */
abstract class DataMapper extends ModelAbstract
{
    /**
     * DataMapper constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->construct();
    }

    /**
     *  Model constructor
     * @return void
     */
    abstract public function construct();

    /**
     * Method with the responsibility of create a record of model
     * @param array|Record $record (null)
     * @param string $alias ('create')
     * @return Record
     * @throws SimplesHookError
     * @throws SimplesRecordReadonlyError
     * @throws SimplesRunTimeError
     */
    final public function create($record = null, string $alias = null): Record
    {
        $record = Record::parse($record);

        foreach ($this->getParents() as $relationship => $parent) {
            /** @var DataMapper $parent */
            $create = $parent->create($record);
            $record->set($relationship, $create->get($parent->getPrimaryKey()));
            $record->import($create->all());
        }

        $action = coalesce($alias, Action::CREATE);

        if (!$this->before($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'before');
        }
        if (!$record->get($this->hashKey)) {
            $record->set($this->hashKey, $this->hashKey());
        }

        $create = $this->configureRecord(Action::CREATE, $record);
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
        $record->merge($create->all());

        if (!$this->after($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'after');
        }
        return $record;
    }

    /**
     * Read records with the filters informed
     * @param array|Record $record (null)
     * @param string $alias ('read')
     * @param bool $trash (false)
     * @param bool $clean (false)
     * @return Collection
     * @throws SimplesHookError
     * @throws SimplesRunTimeError
     * @throws Exception
     */
    final public function read($record = null, string $alias = null, $trash = false, $clean = false): Collection
    {
        $record = Record::parse(coalesce($record, []));

        $action = coalesce($alias, Action::READ);

        if (!$this->before($action, $record)) {
            throw new SimplesHookError(get_class($this), $action, 'before');
        }

        $filters = [];
        $values = [];
        if (!$record->isEmpty()) {
            $filters = $this->parseFilterFields($record->all());
            $values = $this->parseFilterValues($filters);
        }

        if ($this->destroyKeys) {
            $filters[] = $this->getDestroyFilter($this->destroyKeys['at'], $trash);
        }

        $fields = $this->getActionFields(Action::READ, false);
        if ($clean) {
            $fields = $this->clear($fields);
        }

        $order = is_array($this->order) ? $this->order : [$this->order];

        $where = off($this->getClauses(), 'where', []);
        if ($where) {
            $filters = array_merge($filters, $where);
            $values = array_merge($values, off($this->getClauses(), 'values', []));
        }

        $array = $this
            ->source($this->getCollection())
            ->relation($this->parseReadRelations($this->fields))
            ->fields($fields)
            ->order($order)
            ->where($filters)// TODO: needs review
            ->recover($values);

        $this->reset();

        $after = $this->after($action, $record, $array);
        if (!is_array($after)) {
            throw new SimplesHookError(get_class($this), $action, 'after');
        }
        $array = $after;

        return Collection::make($array);
    }

    /**
     * Update the record given
     * @param array|Record $record (null)
     * @param string $alias ('update')
     * @param bool $trash (false)
     * @return Record
     * @throws SimplesActionError
     * @throws SimplesHookError
     * @throws SimplesResourceError
     * @throws SimplesRunTimeError
     */
    final public function update($record = null, string $alias = null, bool $trash = false): Record
    {
        $record = Record::parse($record);

        foreach ($this->getParents() as $parent) {
            /** @var DataMapper $parent */
            $record->import($parent->update($record)->all());
        }

        $action = coalesce($alias, Action::UPDATE);

        $previous = $this->previous($record, $this->hashKey, $trash);

        if ($previous->isEmpty()) {
            throw new SimplesResourceError([$this->getHashKey() => $record->get($this->getHashKey())]);
        }

        if (!$this->before($action, $record, $previous)) {
            throw new SimplesHookError(get_class($this), $action, 'before');
        }

        $record->setPrivate($this->getHashKey());

        $update = $this->configureRecord(Action::UPDATE, $record, $previous, !$trash);
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
            ->where([$filter])// TODO: needs review
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
     * @param string $alias ('destroy')
     * @return Record
     * @throws SimplesActionError
     * @throws SimplesHookError
     * @throws SimplesResourceError
     * @throws SimplesRunTimeError
     */
    final public function destroy($record = null, string $alias = null): Record
    {
        $record = Record::parse($record);

        foreach ($this->getParents() as $parent) {
            /** @var DataMapper $parent */
            $record->import($parent->destroy($record)->all());
        }

        $action = coalesce($alias, Action::DESTROY);

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
                ->where($filters)// TODO: needs review
                ->change($values, [$filter->getValue()]);
        }

        if (!isset($removed)) {
            $removed = $this
                ->source($this->getCollection())
                ->where($filters)// TODO: needs review
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
     * Recycle a destroyed record
     * @param array|Record $record (null)
     * @return Record
     * @throws SimplesActionError
     * @throws SimplesHookError
     * @throws SimplesResourceError
     * @throws SimplesRunTimeError
     * @throws SimplesValidationError
     */
    final public function recycle($record = null): Record
    {
        if (!$this->destroyKeys) {
            throw new SimplesValidationError(
                ['destroyKeys' => 'requires'],
                "Recycle needs the `destroyKeys`"
            );
        }

        $record = Record::parse($record);
        foreach ($this->destroyKeys as $deletedKey) {
            $record->set($deletedKey, __NULL__);
        }

        return $this->update($record, 'recycle', true);
    }

    /**
     * Get total of records based on filters
     * @param Record $record
     * @return int
     * @throws Exception
     * @throws SimplesActionError
     * @throws SimplesHookError
     * @throws SimplesRunTimeError
     */
    final public function count(Record $record): int
    {
        $alias = 'count';
        $collection = $this->getCollection();
        $name = $this->getPrimaryKey();
        $type = Field::AGGREGATOR_COUNT;
        $options = ['alias' => $alias];
        $fields = [Field::make($collection, $name, $type, $options)];

        $this->reset();

        $count = $this
            ->fields($fields)
            ->limit(null)
            ->read($record, null, false)
            ->current();

        $this->reset();

        if (!$count->has($alias)) {
            throw new SimplesActionError(get_class($this), $alias);
        }

        return (int)$count->get($alias);
    }

    /**;
     *
     * @param string $action
     * @param bool $strict
     * @return array|mixed
     */
    protected function getActionFields(string $action, bool $strict = true)
    {
        if (off($this->getClauses(), 'fields')) {
            $fields = off($this->getClauses(), 'fields');
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

    /**
     * @param array $fields
     * @return array
     */
    private function clear(array $fields): array
    {
        foreach ([$this->destroyKeys, $this->createKeys, $this->updateKeys] as $keys) {
            foreach ($keys as $name) {
                unset($fields[$name]);
            }
        }
        return $fields;
    }
}

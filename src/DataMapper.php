<?php

namespace Simples\Model;

use Simples\Data\Collection;
use Simples\Data\Error\SimplesResourceError;
use Simples\Data\Error\SimplesValidationError;
use Simples\Data\Record;
use Simples\Helper\JSON;
use Simples\Kernel\Wrapper;
use Simples\Model\Error\SimplesActionError;
use Simples\Model\Error\SimplesHookError;
use Simples\Persistence\Field;
use Simples\Persistence\Filter;

/**
 * Class DataMapper
 * @package Simples\Model
 */
class DataMapper extends ModelAbstract
{
    /**
     * Method with the responsibility of create a record of model
     * @param array|Record $record (null)
     * @param string $alias ('create')
     * @return Record
     * @throws SimplesHookError
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
     * @return Collection
     * @throws SimplesHookError
     */
    final public function read($record = null, string $alias = null, $trash = false): Collection
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

        $array = $this
            ->source($this->getCollection())
            ->relation($this->parseReadRelations($this->fields))
            ->fields($this->getActionFields(Action::READ, false))
            ->where($filters)// TODO: needs review
            ->recover($values);

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
     * @param string $alias ('update')
     * @param bool $trash (false)
     * @return Record
     * @throws SimplesActionError
     * @throws SimplesHookError
     * @throws SimplesResourceError
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
     * @throws SimplesValidationError
     */
    final public function recycle($record = null): Record
    {
        if (!$this->destroyKeys) {
            $details = ['destroyKeys' => 'requires'];
            $message = "Recycle needs the `destroyKeys`";
            throw new SimplesValidationError($details, $message);
        }
        $record = Record::parse($record);
        foreach ($this->destroyKeys as $deletedKey) {
            $record->set($deletedKey, __NULL__);
        }
        return $this->update($record, 'recycle', true);
    }

    /**
     * Get total of records based on filters
     * @param array|Record $record (null)
     * @return int
     * @throws SimplesActionError
     */
    final public function count($record = null): int
    {
        // Record
        $alias = 'count';
        $count = $this
            ->fields([
                new Field($this->getCollection(), $this->getPrimaryKey(), Field::AGGREGATOR_COUNT, ['alias' => $alias])
            ])
            ->limit(null)
            ->read($record, $alias, false)->current();

        $this->reset();

        if (!$count->has($alias)) {
            throw new SimplesActionError(get_class($this), $alias);
        }

        return (int)$count->get($alias);
    }

    /**
     * @param string $action
     * @param Record $record
     * @param Record $previous (null)
     * @param bool $calculate (false)
     * @return Record
     */
    private function configureRecord(
        string $action,
        Record $record,
        Record $previous = null,
        bool $calculate = true
    ): Record {
        $values = Record::make([]);
        $fields = $this->getActionFields($action);
        foreach ($fields as $field) {
            /** @var Field $field */
            $name = $field->getName();
            if ($record->has($name)) {
                $value = $record->get($name);
            }
            if ($calculate && $field->isCalculated()) {
                $immutable = $record;
                if ($previous) {
                    $immutable = $previous;
                }
                $value = $field->calculate($immutable);
                $record->set($name, $value);
            }
            if (isset($value)) {
                if ($value === __NULL__) {
                    $value = null;
                }
                $values->set($name, $value);
                unset($value);
            }
        }
        return $values;
    }

    /**;
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

    /**
     * @param int $options
     * @return string
     */
    public function getJSON(int $options = 0)
    {
        $fields = [];
        /** @var Field $field */
        foreach ($this->fields as $field) {
            $fields[] = [
                'field' => $field->getName(),
                'type' => $field->getType(),
                'label' => $field->option('label'),
                'grid' => true,
                'form' => ['create', 'show', 'edit'],
                'search' => true,
                'grids' => [
                    'width' => ''
                ],
                'forms' => [
                    'component' => '',
                    'width' => '',
                    'disabled' => false,
                    'order' => 0
                ]
            ];
        }

        return JSON::encode($fields, $options);
    }
}

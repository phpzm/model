<?php

namespace Simples\Model\Resource;

use Exception;
use Simples\Data\Record;
use Simples\Error\SimplesRunTimeError;
use Simples\Helper\JSON;
use Simples\Kernel\Container;
use Simples\Model\DataMapper;
use Simples\Persistence\Field;
use Simples\Persistence\Filter;
use Simples\Persistence\Fusion;

/**
 * Class ModelParser
 * @package Simples\Model\Resources
 */
trait ModelParser
{
    /**
     * Parse the filters of fields
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    protected function parseFilterFields(array $data = []): array
    {
        $filters = [];
        foreach ($data as $name => $value) {
            if (is_array($value) && isset($value['filter'])) {
                $value['filter'] = $this->parseFilterFields($value['filter']);
                $filters[] = $value;
                continue;
            }
            if (!$this->has($name)) {
                $class = static::class;
                throw new SimplesRunTimeError("There is no property `{$name}` in `{$class}`");
            }
            $filters[] = Filter::create($this->get($name), $value);
        }
        return $filters;
    }

    /**
     * Parse the values of the fields
     *
     * @param array $filters
     * @return array
     */
    protected function parseFilterValues(array $filters = []): array
    {
        $values = [];
        foreach ($filters as $filter) {
            if (is_array($filter)) {
                $values = array_merge($values, $this->parseFilterValues($filter['filter']));
                continue;
            }
            /** @var Filter $filter */
            $value = $filter->getParsedValue($this->getDriver());
            if (!is_array($value)) {
                $values[] = $value;
                continue;
            }
            $values = array_merge($values, $value);
        }
        return $values;
    }

    /**
     * @param array $fields
     * @return array
     */
    protected function parseReadRelations(array $fields = []): array
    {
        $join = [];
        /** @var DataMapper $parent */
        foreach ($this->getParents() as $references => $parent) {
            $collection = $parent->getCollection();
            $referenced = $parent->getPrimaryKey();
            $source = $this->getCollection();
            $join[] = Fusion::create($collection, $referenced, $source, $references, false, false);
        }
        foreach ($fields as $field) {
            /** @var Field $field */
            $reference = $field->getReferences();
            if (off($reference, 'fusion')) {
                /** @var DataMapper $instance */
                $instance = Container::instance()->make($reference->class);
                $collection = $instance->getCollection();
                $referenced = $reference->referenced;
                $source = $reference->collection;
                $references = $field->getName();
                $join[] = Fusion::create($collection, $referenced, $source, $references);
            }
        }
        return $join;
    }

    /**
     * @param Record $record
     * @param string $hashKey
     * @param bool $trash (false)
     * @return Record
     */
    protected function previous(Record $record, string $hashKey, bool $trash = false): Record
    {
        $primaryKey = $this->getPrimaryKey();

        $filter = [$hashKey => $record->get($hashKey)];
        if (!$record->get($hashKey)) {
            $filter = [$primaryKey => $record->get($primaryKey)];
        }

        $read = $this->fields(null)->read($filter, null, $trash)->current();
        $previous = Record::make($read->all(), false);
        if (!$previous->isEmpty()) {
            $record->set($primaryKey, $previous->get($primaryKey));
            $record->set($hashKey, $previous->get($hashKey));
        }

        return $previous;
    }

    /**
     * @param string $at
     * @param bool $trash
     * @return Filter
     */
    protected function getDestroyFilter(string $at, bool $trash = false): Filter
    {
        $field = new Field($this->getCollection(), $at, $this->getTimestampValue('at'));

        return Filter::create($field, null, Filter::RULE_BLANK, $trash);
    }

    /**
     * @param string $action
     * @param Record $record
     * @param Record $previous (null)
     * @param bool $calculate (false)
     * @return Record
     */
    public function configureRecord(
        string $action,
        Record $record,
        Record $previous = null,
        bool $calculate = true
    ): Record {
        // $avoid = $this->getAvoid();
        $values = Record::make([]);
        $fields = $this->getActionFields($action);
        foreach ($fields as $field) {
            /** @var Field $field */
            $name = $field->getName();
            /*
            if (in_array($name, $avoid)) {
                continue;
            }
             */
            if ($record->has($name)) {
                $value = $record->get($name);
            }
            if ($calculate && $field->isCalculated()) {
                $value = $this->resolveCalculated($field, $record, $previous);
            }
            if ($calculate && $field->isMutable()) {
                $value = $this->resolveMutation($field, $record);
            }
            if (isset($value)) {
                $values->set($name, $this->resolveValue($value));
                unset($value);
            }
        }
        return $values;
    }

    /**
     * @param Field $field
     * @param Record $record
     * @param Record $previous (null)
     * @return mixed
     */
    protected function resolveCalculated(Field $field, Record $record, Record $previous = null)
    {
        $immutable = $record;
        if ($previous) {
            $immutable = $previous;
        }
        return $field->calculate($immutable);
    }

    /**
     * @param Field $field
     * @param Record $record
     * @return mixed
     */
    protected function resolveMutation(Field $field, Record $record)
    {
        $callable = $field->option('mutator');
        return $callable($record->get($field->getName()));
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    protected function resolveValue($value)
    {
        if ($value === __NULL__) {
            $value = null;
        }
        return $value;
    }

    /**
     * @return array
     */
    protected function getAvoid(): array
    {
        $avoid = [];
        foreach ([$this->destroyKeys, $this->createKeys, $this->updateKeys] as $keys) {
            foreach ($keys as $name) {
                $avoid[] = $name;
            }
        }
        return $avoid;
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
                'grid' => false,
                'form' => ['create', 'show', 'edit'],
                'search' => false,
                'grids' => [
                    'width' => ''
                ],
                'forms' => [
                    'component' => '',
                    'options' => array_map(function ($value) {
                        return [
                            'label' => '',
                            'value' => $value
                        ];
                    }, $field->getEnum()),
                    'width' => '',
                    'disabled' => false,
                    'order' => 0
                ]
            ];
        }

        return JSON::encode($fields, $options);
    }
}

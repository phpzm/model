<?php

namespace Simples\Model\Resource;

use Exception;
use Simples\Data\Record;
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
     * @param array $data
     * @return array
     * @throws Exception
     */
    protected function parseFilterFields(array $data): array
    {
        $filters = [];
        foreach ($data as $name => $value) {
            if (!$this->has($name)) {
                $value['filter'] = $this->parseFilterFields($value['filter']);
                $filters[] = $value;
                continue;
            }
            $filters[] = Filter::create($this->get($name), $value);
        }
        return $filters;
    }

    /**
     * @param array $filters
     * @return array
     */
    protected function parseFilterValues(array $filters): array
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
    protected function parseReadRelations(array $fields): array
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
     * @return Record
     */
    protected function previous(Record $record, string $hashKey): Record
    {
        $primaryKey = $this->getPrimaryKey();

        $filter = [$hashKey => $record->get($hashKey)];
        if (!$record->get($hashKey)) {
            $filter = [$primaryKey => $record->get($primaryKey)];
        }

        $previous = $this->fields(null)->read($filter)->current();
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
        $field = new Field($this->getCollection(), $at, Field::TYPE_DATETIME);

        return Filter::create($field, null, Filter::RULE_BLANK, $trash);
    }
}

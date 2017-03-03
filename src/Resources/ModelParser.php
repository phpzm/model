<?php

namespace Simples\Model\Resources;

use Exception;
use Simples\Data\Record;
use Simples\Error\SimplesRunTimeError;
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
            $field = $this->get($name);
            if (is_null($field)) {
                throw new SimplesRunTimeError("Invalid field name '{$name}'");
            }
            $filters[] = new Filter($field, $value);
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
        /** @var Filter $filter */
        foreach ($filters as $filter) {
            $value = $filter->getParsedValue();
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
        foreach ($this->getParents() as $relationship => $parent) {
            $join[] = new Fusion(
                $parent->getCollection(), $parent->getPrimaryKey(), $this->getCollection(), $relationship, false, false
            );
        }
        foreach ($fields as $field) {
            /** @var Field $field */
            $reference = $field->getReferences();
            if (off($reference, 'class')) {
                /** @var DataMapper $instance */
                $instance = Container::box()->make($reference->class);
                $join[] = new Fusion($instance->getCollection(), $reference->referenced, $reference->collection,
                    $field->getName());
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
     * @return Filter
     */
    protected function getDestroyFilter(string $at): Filter
    {
        $field = new Field($this->getCollection(), $at, Field::TYPE_DATETIME);
        return new Filter($field, Filter::apply(Filter::RULE_BLANK));
    }
}

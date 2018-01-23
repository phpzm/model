<?php

namespace Simples\Model\Resource;

use Simples\Data\Record;
use Simples\Model\ModelAbstract;
use Simples\Persistence\Field;

/**
 * Class ModelAggregation
 * @package Simples\Model\Resource
 */
trait ModelAggregation
{
    /**
     * @param Record $filter
     * @param string $field (null)
     * @param string $aggregator (null)
     * @return float
     */
    public function sum(Record $filter, string $field = null, string $aggregator = null)
    {
        return (int)$this->aggregate($filter, 'sum', Field::AGGREGATOR_SUM, $field, [$aggregator]);
    }

    /**
     * @param Record $filter
     * @param string $field (null)
     * @param string $aggregator (null)
     * @return mixed
     */
    public function min(Record $filter, string $field = null, string $aggregator = null)
    {
        return $this->aggregate($filter, 'min', Field::AGGREGATOR_MIN, $field, [$aggregator]);
    }

    /**
     * @param Record $filter
     * @param string $field (null)
     * @param string $aggregator (null)
     * @return mixed
     */
    public function max(Record $filter, string $field = null, string $aggregator = null)
    {
        return $this->aggregate($filter, 'max', Field::AGGREGATOR_MAX, $field, [$aggregator]);
    }

    /**
     * @param Record $filter
     * @param string $alias
     * @param string $type
     * @param string $field (null)
     * @param array $group (null)
     * @return mixed
     */
    public function aggregate(Record $filter, string $alias, string $type, string $field = null, array $group = null)
    {
        $collection = $this->getCollection();
        $name = $field ? $field : $this->getPrimaryKey();
        $fields = [
            Field::make($collection, $name, $type, ['alias' => $alias])
        ];
        if (!$group) {
            $group = [$name];
        }

        $this->reset();

        /** @var ModelAbstract $this */
        $aggregator = $this
            ->log(true)
            ->fields($fields)
            ->limit(null)
            ->group($group)
            ->read($filter, null, false)
            ->current();

        $this->reset();

        return $aggregator->get($alias);
    }
}

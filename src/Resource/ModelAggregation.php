<?php

namespace Simples\Model\Resource;

use Simples\Data\Record;
use Simples\Model\Error\SimplesActionError;
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
     * @return float
     */
    public function sum(Record $filter, string $field = null)
    {
        return (int)$this->aggregate($filter, 'sum', Field::AGGREGATOR_SUM, $field);
    }

    /**
     * @param Record $filter
     * @param string $field (null)
     * @return mixed
     */
    public function min(Record $filter, string $field = null)
    {
        return $this->aggregate($filter, 'min', Field::AGGREGATOR_MIN, $field);
    }

    /**
     * @param Record $filter
     * @param string $field (null)
     * @return mixed
     */
    public function max(Record $filter, string $field = null)
    {
        return $this->aggregate($filter, 'max', Field::AGGREGATOR_MAX, $field);
    }

    /**
     * @param Record $filter
     * @param string $alias
     * @param string $type
     * @param string $field (null)
     * @param array $group (null)
     * @return mixed
     * @throws SimplesActionError
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
        $aggregator = $this
            ->fields($fields)
            ->limit(null)
            ->group($group)
            ->read($filter, $alias, false)->current();

        $this->reset();

        if (!$aggregator->has($alias)) {
            throw new SimplesActionError(get_class($this), $alias);
        }

        return $aggregator->get($alias);
    }
}

<?php

namespace Simples\Model;

use Simples\Persistence\Engine;
use Simples\Data\Collection;
use Simples\Data\Record;

/**
 * Class ModelContract
 * @package Simples\Model
 */
abstract class ModelContract extends Engine
{
    /**
     * Method with the responsibility of create a record of model
     * @param array|Record $record (null)
     * @param string $alias (null)
     * @return Record
     */
    abstract public function create($record = null, string $alias = null): Record;

    /**
     * Read records with the filters informed
     * @param array|Record $record (null)
     * @param string $alias (null)
     * @param bool $trash (false)
     * @return Collection
     */
    abstract public function read($record = null, string $alias = null, $trash = false): Collection;

    /**
     * Update the record given
     * @param array|Record $record (null)
     * @param string $alias (null)
     * @param bool $trash (false)
     * @return Record
     */
    abstract public function update($record = null, string $alias = null, bool $trash = false): Record;

    /**
     * Remove the given record of database
     * @param array|Record $record (null)
     * @param string $alias (null)
     * @return Record
     */
    abstract public function destroy($record = null, string $alias = null): Record;
}

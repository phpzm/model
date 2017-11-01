<?php

namespace Simples\Model\Repository;

use Simples\Data\Collection;
use Simples\Data\Error\SimplesValidationError;
use Simples\Data\Record;
use Simples\Data\Validator;
use Simples\Error\SimplesRunTimeError;
use Simples\Kernel\Container;
use Simples\Model\Action;
use Simples\Model\ModelAbstract;
use Simples\Model\Repository\Resource\ValidationParser;

/**
 * Class ModelRepository
 * @package Simples\Model\Repository
 */
class ModelRepository
{
    /**
     * @trait ValidationParser
     */
    use ValidationParser;

    /**
     * @var ModelAbstract
     */
    protected $model;

    /**
     * @var Validator
     */
    private $validator;

    /**
     * @var bool
     */
    private $clean;

    /**
     * ApiRepository constructor.
     * @param ModelAbstract $model
     * @param Validator|null $validator
     */
    public function __construct(ModelAbstract $model, Validator $validator = null)
    {
        $this->model = $model;

        $this->validator = $validator ?? new Validator();
    }

    /**
     * @return $this
     * @throws SimplesRunTimeError
     */
    public static function instance()
    {
        return Container::instance()->make(static::class);
    }

    /**
     * @param Record|array $record
     * @param string $action (null)
     * @return Record
     * @throws SimplesRunTimeError
     * @throws SimplesValidationError
     */
    public function create($record, string $action = null): Record
    {
        $record = Record::parse($record);

        $action = coalesce($action, Action::CREATE);

        $this->model->configureFields($action, $record);

        $record->import($this->model->getDefaults($action, $record));

        $validators = $this->getValidators($this->getFields(), $record);
        $errors = $this->parseValidation($validators);
        if (!$errors->isEmpty()) {
            throw new SimplesValidationError($errors->all(), get_class($this));
        }

        return $this->model->create($record, $action);
    }

    /**
     * @param Record|array $record
     * @param string $action (null)
     * @param bool $trash (false)
     * @return Collection
     * @throws SimplesRunTimeError
     */
    public function read($record, string $action = null, $trash = false): Collection
    {
        $record = Record::parse($record);

        $action = coalesce($action, Action::READ);

        $clean = $this->clean;
        $this->clean = false;

        return $this->model->read($record, $action, $trash, $clean);
    }

    /**
     * @param Record|array $record
     * @param string $action (null)
     * @return Record
     * @throws SimplesRunTimeError
     * @throws SimplesValidationError
     */
    public function update($record, string $action = null): Record
    {
        $record = Record::parse($record);

        $action = coalesce($action, Action::UPDATE);

        $this->model->configureFields($action, $record);

        $record->import($this->model->getDefaults($action, $record));

        $hashKey = $this->model->getHashKey();
        if ($record->get($hashKey)) {
            $primaryKey = $this->model->getPrimaryKey();
            $value = $this->find([$hashKey => $record->get($hashKey)], [$primaryKey])->current()->get($primaryKey);
            $record->set($primaryKey, $value);
        }

        $validators = $this->getValidators($this->getFields(), $record);
        $errors = $this->parseValidation($validators);
        if (!$errors->isEmpty()) {
            throw new SimplesValidationError($errors->all(), get_class($this));
        }

        return $this->model->update($record, $action);
    }

    /**
     * @param Record|array $record
     * @param string $action (null)
     * @return Record
     * @throws SimplesRunTimeError
     */
    public function destroy($record, string $action = null): Record
    {
        $record = Record::parse($record);

        $action = coalesce($action, Action::DESTROY);

        return $this->model->destroy($record, $action);
    }

    /**
     * @param Record|array $record
     * @return Record
     * @throws SimplesRunTimeError
     */
    public function recycle($record): Record
    {
        $record = Record::parse($record);

        return $this->model->recycle($record);
    }

    /**
     * @param array $record
     * @return int
     */
    public function count(array $record): int
    {
        return $this->model->count(Record::make($record));
    }

    /**
     * @param array $filter
     * @param array $order (null)
     * @param int $start
     * @param int $end
     * @param bool $trash
     * @return Collection
     */
    public function search(array $filter, array $order = null, $start = null, $end = null, $trash = false): Collection
    {
        if (is_array($order) && count($order)) {
            $this->model->order($order);
        }
        if (!is_null($start) && !is_null($end)) {
            $this->model->limit([$start, $end]);
        }
        return $this->model->read($filter, null, $trash);
    }

    /**
     * @param array $filters
     * @param array $fields
     * @return Collection
     */
    public function find(array $filters, array $fields): Collection
    {
        return $this->model->fields($fields)->read(Record::make($filters));
    }

    /**
     * @param $id
     * @return Record
     */
    public function findById($id): Record
    {
        return $this->search([
            $this->getModel()->getPrimaryKey() => $id
        ])->current();
    }

    /**
     * @param Record $record
     * @param array $binds
     * @return Record
     */
    public function transform(Record $record, array $binds): Record
    {
        $transformed = [];
        foreach ($binds as $key => $value) {
            $transformed[$value] = $record->get($key);
        }
        return Record::make($transformed);
    }

    /**
     * @return ModelAbstract
     */
    public function getModel(): ModelAbstract
    {
        return $this->model;
    }

    /**
     * @return Validator
     */
    public function getValidator(): Validator
    {
        return $this->validator;
    }

    /**
     * @param $record
     * @return Record
     */
    public function unique($record = null): Record
    {
        $exists = $this->model->read($record);
        if ($exists->size()) {
            return $exists->current();
        }
        return $this->model->create($record);
    }

    /**
     * @return array
     */
    public function getFields(): array
    {
        return $this->model->getFields(Action::RECOVER, false);
    }

    /**
     * @return string
     */
    public function getHashKey(): string
    {
        return $this->model->getHashKey();
    }

    /**
     *
     * @param bool $logging
     * @return ModelRepository
     */
    public function log($logging = true): ModelRepository
    {
        $this->model->log($logging);
        return $this;
    }

    /**
     * @return $this
     */
    public function clean()
    {
        $this->clean = true;

        return $this;
    }
}

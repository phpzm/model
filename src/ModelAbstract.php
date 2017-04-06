<?php

namespace Simples\Model;

use Simples\Data\Record;
use Simples\Error\SimplesRunTimeError;
use Simples\Kernel\Container;
use Simples\Model\Resource\ModelParser;
use Simples\Model\Resource\ModelTrigger;
use Simples\Model\Resource\ModelTimestamp;
use Simples\Persistence\Field;

/**
 * Class AbstractModel
 * @package Simples\Model
 */
abstract class ModelAbstract extends ModelContract
{
    /**
     * @trait ModelTrigger, ModelParser, Timestamp
     */
    use ModelTrigger, ModelParser, ModelTimestamp;

    /**
     * Connection id
     * @var string
     */
    protected $connection;

    /**
     * Data source name
     * @var string
     */
    private $collection = '';

    /**
     * Collections parents created by extends
     * @var array
     */
    private $parents = [];

    /**
     * Fields of model
     * @var array
     */
    protected $fields = [];

    /**
     * @var array
     */
    private $maps = [];

    /**
     * Key used to relationships and to represent the primary key database
     * @var string
     */
    private $primaryKey = '';

    /**
     * Field with a unique hash what can be used to find a record and can be
     * created by client
     * @var string
     */
    protected $hashKey = '_id';

    /**
     * Fields to persist the creation object
     * @var array
     */
    protected $createKeys = [
        'at' => '_created_at',
        'by' => '_created_by'
    ];

    /**
     * Fields to persist details about the update
     * @var array
     */
    protected $updateKeys = [
        'at' => '_changed_at',
        'by' => '_changed_by'
    ];

    /**
     * Fields to persist details about the destroy
     * @var array
     */
    protected $destroyKeys = [
        'at' => '_destroyed_at',
        'by' => '_destroyed_by'
    ];

    /**
     * AbstractModel constructor to configure a new instance
     * @param string $connection (null)
     */
    public function __construct($connection = null)
    {
        parent::__construct($this->connection($connection));
        /*
        foreach (array_merge($this->createKeys, $this->updateKeys, $this->destroyKeys) as $type => $name) {
            $this->addField($name, $type === 'at' ? Field::TYPE_DATETIME : Field::TYPE_STRING);
        }
        */
    }

    /**
     * Configure the instance with reference properties
     * @param string $collection
     * @param string $primaryKey
     * @param string $relationship
     * @return $this
     * @throws SimplesRunTimeError
     */
    protected function configure(string $collection, string $primaryKey, string $relationship = '')
    {
        if ($this->collection) {
            $this->parents[$relationship] = clone $this;
            $this->add($relationship)->integer()->collection($collection)->update(false);
            if (!$relationship) {
                throw new SimplesRunTimeError("When extending one model you need give a name to relationship");
            }
        }
        $this->collection = $collection;
        $this->primaryKey = $primaryKey;

        $this->add($this->hashKey)->hashKey();
        if ($this->destroyKeys) {
            foreach ($this->destroyKeys as $key => $field) {
                $this->add($field)->type($this->getTimestampType($key))->recover(false);
            }
        }
        $this->add($primaryKey)->primaryKey();

        return $this;
    }

    /**
     * @return $this
     */
    public static function instance()
    {
        return Container::instance()->make(static::class);
    }

    /**
     * Parse the connection name and choose a source to it
     * @param $connection
     * @return string
     */
    private function connection($connection): string
    {
        if (!is_null($connection)) {
            $this->connection = $connection;
        } elseif (is_null($this->connection)) {
            $this->connection = env('DEFAULT_DATABASE');
        }
        return $this->connection;
    }

    /**
     * Recycle a destroyed record
     * @param array|Record $record (null)
     * @return Record
     */
    abstract public function recycle($record = null): Record;

    /**
     * Get total of records based on filters
     * @param array|Record $record (null)
     * @return int
     */
    abstract public function count($record = null): int;

    /**
     * @param string $name
     * @param string $type
     * @param array $options
     * @return Field
     */
    protected function add(string $name, string $type = '', array $options = []): Field
    {
        $field = new Field($this->collection, $name, $type, $options);
        $this->fields[$name] = $field;

        return $this->fields[$name];
    }

    /**
     * Allow use this field like readonly in read filtering and getting it in record
     * @param string $name
     * @param string $relationship
     * @param array $options
     * @return Field
     * @throws SimplesRunTimeError
     */
    protected function import(string $name, string $relationship, array $options = []): Field
    {
        $source = $this->get($relationship);
        $reference = $source->getReferences();

        $class = off($reference, 'class');
        if (!class_exists($class)) {
            throw new SimplesRunTimeError("Cant not import '{$name}' from '{$class}'");
        }

        /** @var DataMapper $class */
        $import = $class::instance()->get($name);

        $options = array_merge($import->getOptions(), $options);

        $from = new Field($import->getCollection(), $name, $import->getType(), $options);
        $this->fields[$name] = $from->from($source);

        return $this->fields[$name];
    }

    /**
     * @param string $name
     * @return Field
     */
    final public function get(string $name): Field
    {
        return off($this->fields, $name);
    }

    /**
     * @param string $name
     * @return bool
     */
    final public function has(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    /**
     * @param string $source
     * @param string $target
     */
    public function map(string $source, string $target)
    {
        $this->maps[$source] = $target;
    }

    /**;
     *
     * @param string $action
     * @param bool $strict
     * @return array
     */
    final public function getFields(string $action = '', bool $strict = true): array
    {
        $method = '';
        switch ($action) {
            case Action::CREATE:
                $method = 'isCreate';
                break;
            case Action::READ:
                $method = 'isRead';
                break;
            case Action::UPDATE:
                $method = 'isUpdate';
                break;
            case Action::RECOVER:
                $method = 'isRecover';
                break;
        }

        return $this->filterFields($this->getCollection(), $method, $strict);
    }

    /**
     * @return string
     */
    final public function hashKey(): string
    {
        return uniqid();
    }

    /**
     * @return array
     */
    final public function getParents(): array
    {
        return $this->parents;
    }

    /**
     * @return string
     */
    final public function getCollection(): string
    {
        return $this->collection;
    }

    /**
     * @return string
     */
    final public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * @return string
     */
    final public function getHashKey(): string
    {
        return $this->hashKey;
    }
}

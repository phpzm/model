<?php

namespace Simples\Model\Repository\Resource;

use Simples\Data\Record;
use Simples\Data\Validation;
use Simples\Persistence\Field;

/**
 * Class ValidationParser
 * @package Simples\Model\Repository\Resource
 */
trait ValidationParser
{
    /**
     * @param $validators
     * @return Record
     */
    final public function parseValidation($validators)
    {
        return $this->getValidator()->parse($validators);
    }

    /**
     * @param array $fields
     * @param Record $record
     * @return array
     */
    final public function getValidators(array $fields, Record $record): array
    {
        $validation = new Validation();
        foreach ($fields as $key => $field) {
            $validator = $this->parseValidator($field, $record);
            if ($validator) {
                $validation->add($key, $record->get($key), $validator);
            }
        }
        return $validation->rules();
    }

    /**
     * @param Field $field
     * @param Record $record
     * @return array|null
     */
    final protected function parseValidator(Field $field, Record $record)
    {
        $validators = $field->getValidators();
        if ($validators) {
            return $this->parseValidatorRules($validators, $field, $record);
        }
        return null;
    }

    /**
     * @param array $validators
     * @param Field $field
     * @param Record $record
     * @return array
     */
    final protected function parseValidatorRules(array $validators, Field $field, Record $record)
    {
        $rules = [];
        foreach ($validators as $validator => $options) {
            if (!$options) {
                $options = [];
            }
            if (!is_array($options)) {
                $options = [$options];
            }
            switch ($validator) {
                case 'unique':
                    $primaryKey = $this->model->getPrimaryKey();
                    $options = array_merge($options, [
                        'class' => get_class($this),
                        'field' => $field->getName(),
                        'primaryKey' => [
                            'name' => $primaryKey,
                            'value' => $record->get($primaryKey)
                        ]
                    ]);
                    break;
            }
            if (count($field->getEnum())) {
                $options = array_merge($options, [
                    'enum' => $field->getEnum()
                ]);
            }
            $rules[$validator] = $options;
        }
        return $rules;
    }
}

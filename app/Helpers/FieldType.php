<?php

namespace App\Helpers;

class FieldType
{
    const NAME_MAPPINGS = [
        'tinyint' => 'tinyInteger',
        'bigint' => 'bigInteger',
        'int' => 'integer',
        'varchar' => 'string'
    ];


    public string $name = '';
    public array $params = [];
    public ?string $setting = null;

    public function set(string $fieldType, string $fieldName): self
    {
        $filterFieldTypeParams = [
            'tinyInteger' => function ($x) {
                return [];
            },
            'bigInteger' => function ($x) {
                return [];
            },
            'integer' => function ($x) {
                return [];
            },
            'increments' => function ($x) {
                return [];
            }
        ];

        $fieldTypeSplit = explode(' ', $fieldType);

        $this->setting = current($fieldTypeSplit);


        $fieldTypeSettingsSplit = explode('(', $this->setting);

        $fieldTypeName = $fieldTypeSettingsSplit[0];
        $fieldTypeName = strtolower($fieldTypeName);
        $fieldTypeName = array_key_exists($fieldTypeName, self::NAME_MAPPINGS)
            ? self::NAME_MAPPINGS[$fieldTypeName]
            : $fieldTypeName;

        $fieldTypeParamsString = explode(')', current($fieldTypeSettingsSplit))[0];
        $fieldTypeParams = $fieldTypeParamsString != ''
            ? explode(',', $fieldTypeParamsString)
            : [];


        $this->params = array_key_exists($fieldTypeName, $filterFieldTypeParams)
            ? $filterFieldTypeParams[$fieldTypeName]($fieldTypeParams)
            : $fieldTypeParams;

        $this->name = $fieldTypeName;
        if (stripos($this->setting, 'enum') !== false) {
            !d($fieldType);
        }

        return $this;
    }
}

<?php

namespace App\Helpers;

use Illuminate\Database\Schema\Blueprint;
use ReflectionClass;

class MigrationField
{
    const NAME_MAPPINGS = [
        'tinyint' => 'tinyInteger',
        'mediumint' => 'mediumInteger',
        'bigint' => 'bigInteger',
        'int unsigned' => 'unsignedInteger',
        'tinyint unsigned' => 'unsignedTinyInteger',
        'mediumint unsigned' => 'unsignedMediumInteger',
        'bigint unsigned' => 'unsignedBigInteger',
        'int' => 'integer',
        'varchar' => 'string',
        'tinytext' => 'tinyText',
        'mediumtext' => 'mediumText',
        'longtext' => 'longText',
    ];

    protected string $migrationMethod = '';
    public string $methodName = '';
    public ?string $setting = null;

    protected $reflectionClass;
    protected string $sqlFieldType;
    protected string $fieldName;

    public function __construct( string $fieldName, string $fieldType)
    {
        $this->fieldName = $fieldName;
        $this->sqlFieldType = $fieldType;
        $this->reflectionClass = new ReflectionClass(Blueprint::class);

        // Test for parentheses
        if (preg_match('/^.*[\(\)].*$/', $fieldType, $matches)) {
            $this->migrationMethod = $this->buildMethodWithParameters();
        } else {
            // If incoming field has multiple parts, get just the first
            $this->sqlFieldType = current(explode(' ', $fieldType));
            $methodName = self::NAME_MAPPINGS[$fieldType] ?? $fieldType;
            if (! method_exists(Blueprint::class, $methodName)) {
                dd($methodName);
            }
            $this->methodName = $methodName;
            $this->migrationMethod = $methodName . "('" . $this->fieldName . "')";
        }
    }

    public function getMethod(): string
    {
        return $this->migrationMethod;
    }

    public function getMethodName(): string
    {
        return $this->methodName;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function getSqlFieldType(): string
    {
        return $this->sqlFieldType;
    }

    public function setFieldName(string $fieldName)
    {
        $this->fieldName = $fieldName;
    }

    protected function buildMethodWithParameters(): string
    {
        // Break into parts.
        preg_match('/([\w]+)(.*)/', $this->sqlFieldType, $matches);
        $sqlFieldType = $matches[1];
        $this->sqlFieldType = $sqlFieldType;
        $methodName = self::NAME_MAPPINGS[$sqlFieldType] ?? $sqlFieldType;
        $this->methodName = $methodName;

        $params = $matches[2];

        $paramTypes = $this->getParamTypes($methodName);
        if (count($paramTypes) > 1) {
            switch ($paramTypes[1]) {
                case 'array':
                    $params = str_replace(['(', ')'], ['[', ']'], $params);
                default:
                    $params = str_replace(['(', ')'], '', $params);

            }
        }

        return $methodName . "('" . $this->fieldName . "', " . $params . ')';
    }

    protected function getParamTypes(string $type): array
    {
        $paramTypes = [];

        $method =  $this->reflectionClass->getMethod($type);
        foreach ($method->getParameters() as $parameter) {
            $paramTypes[] = (string) $parameter->getType();
        }

        return $paramTypes;
    }
}

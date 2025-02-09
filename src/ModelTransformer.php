<?php

namespace Nolanos\LaravelModelTypescriptTransformer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Transformers\Transformer;

use ReflectionClass;
use ReflectionMethod;
use Illuminate\Database\Eloquent\Relations\Relation;

class ModelTransformer implements Transformer
{
    public function transform(ReflectionClass $class, string $name): TransformedType|null
    {
        if (!is_subclass_of($class->name, Model::class)) {
            return null;
        }
        /** @var Model $modelInstance */
        $modelInstance = $class->newInstanceWithoutConstructor();

        $relations = $this->defineRelations($modelInstance);

        $typed_relations = [];

        foreach ($relations as $relation) {
            $typed_relations[] = $relation;
        }

        $types = array_merge($this->defineTypes($modelInstance, $class, $name), $typed_relations);

        return TransformedType::create(
            $class,
            $name,
            "{\n" . implode("\n", $types) . "\n}",
        );
    }

    private function defineTypes($modelInstance, ReflectionClass $class, string $name): array
    {

        $table = $modelInstance->getTable();

        $hidden = $modelInstance->getHidden();
        $casts = $modelInstance->getCasts();
        $appends = $modelInstance->getAppends();

        $columns = Schema::getColumns($table);
        $columnNames = array_map(fn($col) => $col['name'], $columns);

        $serializedColumnNames = array_diff($columnNames, $hidden);

        $typescriptProperties = [];

        foreach ($serializedColumnNames as $index => $propertyName) {
            $column = $columns[$index];
            $isNullable = $column['nullable'];

            if (array_key_exists($propertyName, $casts)) {
                $typescriptType = $this->mapCastToType($casts[$propertyName]);
            } else {
                $typescriptType = $this->mapTypeNameToJsonType($column['type_name']);
            }

            $typescriptPropertyDefinition = "$propertyName: $typescriptType";

            if ($isNullable) {
                $typescriptPropertyDefinition .= ' | null';
            }

            $typescriptProperties[] = $typescriptPropertyDefinition;
        }

        foreach ($appends as $append) {
            $type = 'any';
            if ($comment = $class->getDocComment()) {
                $matches = [];
                $regex = '/@property\s+([^\s]+)\s+\$' . $append . '\s*\n/';

                preg_match($regex, $comment, $matches);

                // dump($comment, $append, $regex, $matches);

                if (count($matches) > 1) {
                    $type = $this->mapCastToType($matches[1]);
                }
            }

            $typescriptProperties[] = "$append: $type";
        }

        return $typescriptProperties;
    }

    private function defineRelations($model): array
    {

        $reflection = new ReflectionClass($model);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        $ownMethods = array_filter($methods, fn($method) => $method->class === get_class($model));
        $relations = [];

        foreach ($ownMethods as $method) {
            // Do a quick check if the methods requests a param, if so, skip
            if(!empty($method->getParameters())) continue; 

            $relationship = $model->{$method->name}();

            if($relationship instanceof Relation) {
                $relatedName = class_basename($relationship->getRelated());
                $type = strpos(strtolower(class_basename($relationship)), 'many') ? $relatedName."[]" : "$relatedName";
                $relations[] = "$method->name: $type";
            }
        }

        return $relations;
    }

    private function mapTypeNameToJsonType(string $columnType): string
    {
        // Map Laravel column types to TypeScript types
        return match ($columnType) {
            // Strings
            'uuid', 'string', 'text', 'varchar', 'character varying', 'date', 'datetime', 'timestamp', 'timestamp without time zone', 'bpchar', 'timestamptz', 'time', 'bytea', 'blob' => 'string',
            // Numbers
            'integer', 'bigint', 'int2', 'int4', 'int8', 'float', 'double', 'decimal', 'float8', 'numeric' => 'number',
            // Booleans
            'boolean', 'bool' => 'boolean',
            // Unknown
            default => 'unknown /* ' . $columnType . ' */', // Fallback for other types
        };
    }

    private function mapCastToType(string $cast): string
    {
        if (enum_exists($cast)) {
            return implode(' | ', array_map(fn($case) => "'$case->value'", $cast::cases()));
        }

        return match ($cast) {
            'boolean', 'bool' => 'boolean',
            'int', 'float' => 'number',
            'string', 'datetime', 'timestamp', 'date', 'uuid' => 'string',
            'array', 'object' => 'any',
            'collection' => 'any[]',
            default => 'unknown /* ' . $cast . ' */',
        };
    }
}

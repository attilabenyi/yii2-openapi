<?php

/**
 * @copyright Copyright (c) 2018 Carsten Brandt <mail@cebe.cc> and contributors
 * @license https://github.com/cebe/yii2-openapi/blob/master/LICENSE
 */

namespace cebe\yii2openapi\lib;

use cebe\yii2openapi\lib\items\JunctionSchemas;
use cebe\yii2openapi\lib\openapi\ComponentSchema;
use Yii;
use yii\base\Exception;
use yii\helpers\StringHelper;
use function count;

/**
 * Convert OpenAPI description into a database schema.
 * There are two options:
 * 1. let the generator guess which schemas need a database table
 *    for storing their data and which do not.
 * 2. Explicitly define schemas which represent a database table by adding the
 *    `x-table` property to the schema.
 * The [[]]
 * OpenApi Schema definition rules for database conversion:
 * https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.3.md#schema-object
 * components:
 *     schemas:
 *        ModelName: #(table name becomes model_names)
 *            description: #(optional, become as model class comment)
 *            required: #(list of required property names that can't be nullable)
 *               - id
 *               - some
 *            x-table: custom_table #(explicit database table name)
 *            x-indexes: #(list of indexes - property names only, index names will be autogenerated)
 *                - propertyName
 *                - propertyName1,propertyName2
 *                - 'gin:propertyName'  #(index type prefix - using("gin") will be added) (For postgres only!)
 *                - 'unique:propertyName'  #(unique attributes)
 *                Use propertyNames, if property is foreignKey fkcolumn_id will be resolved automatically
 *            x-pk: pid #(optional, primary key name if it called not "id") (composite keys not supported yet)
 *            properties: #(table columns and relations)
 *               prop_name:
 *                  type: #(one of common types string|integer|number|boolean|array)
 *                  format: #(see https://github.com/OAI/OpenAPI-Specification/blob/master/versions/3.0.3.md#dataTypes)
 *                  readOnly: true/false #(If true, should be skipped from validation rules)
 *                  minimum: #(numeric value, applied for validation rules and faker generation)
 *                  maximum: #(numeric value, applied for integer|number validation rules and faker generation)
 *                  maxLength: #(numeric value, applied for database column size limit!, also can be applied for validation)
 *                  minLength: #(numeric value, can be applied for validation rules)
 *                  default: #(int|string, default value, used for database migration and model rules)
 *                  x-db-type: #(Custom database type like JSON, JSONB, CHAR, VARCHAR, UUID, etc )
 *                  x-faker: #(custom faker generator, for ex '$faker->gender')
 *                  description: #(optional, used for comment)
 */
class SchemaToDatabase
{
    /**
     * @var \cebe\yii2openapi\lib\Config
     */
    protected $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @return array|\cebe\yii2openapi\lib\items\DbModel[]
     * @throws \cebe\openapi\exceptions\IOException
     * @throws \cebe\openapi\exceptions\TypeErrorException
     * @throws \cebe\openapi\exceptions\UnresolvableReferenceException
     * @throws \cebe\yii2openapi\lib\exceptions\InvalidDefinitionException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function prepareModels():array
    {
        $models = [];
        $openApi = $this->config->getOpenApi();
        $junctions = $this->findJunctionSchemas();
        foreach ($openApi->components->schemas as $schemaName => $openApiSchema) {
            $schema = Yii::createObject(ComponentSchema::class, [$openApiSchema]);

            if (!$this->canGenerateModel($schemaName, $schema)) {
                continue;
            }
            if ($junctions->isJunctionSchema($schemaName)) {
                $schemaName = $junctions->trimPrefix($schemaName);
            }
            /**@var \cebe\yii2openapi\lib\AttributeResolver $resolver */
            $resolver = Yii::createObject(AttributeResolver::class, [$schemaName, $schema, $junctions]);
            $models[$schemaName] = $resolver->resolve();
        }
        foreach ($models as  $model) {
            foreach ($model->many2many as $relation) {
                if (isset($models[$relation->viaModelName])) {
                    $relation->hasViaModel = true;
                }
                $relation->pkAttribute = $model->getPkAttribute();
                $relation->relatedPkAttribute = $models[$relation->relatedSchemaName]->getPkAttribute();
            }
        }

        // TODO generate inverse relations

        return $models;
    }

    /**
     * @return \cebe\yii2openapi\lib\items\JunctionSchemas
     * @throws \cebe\openapi\exceptions\IOException
     * @throws \cebe\openapi\exceptions\TypeErrorException
     * @throws \cebe\openapi\exceptions\UnresolvableReferenceException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public function findJunctionSchemas():JunctionSchemas
    {
        $junctions = [];
        $openApi = $this->config->getOpenApi();
        foreach ($openApi->components->schemas as $schemaName => $openApiSchema) {
            /**@var ComponentSchema $schema */
            $schema = Yii::createObject(ComponentSchema::class, [$openApiSchema]);
            if ($schema->isNonDb()) {
                continue;
            }
            if (!StringHelper::startsWith($schemaName, JunctionSchemas::PREFIX)) {
                continue;
            }

            if (!$this->canGenerateModel($schemaName, $schema)) {
                continue;
            }

            $propertyMap = [];
            $tableName = $schema->resolveTableName($schemaName);
            foreach ($schema->getProperties() as $property) {
                if (!$property->isReference() || !$property->isRefPointerToSchema()) {
                    continue;
                }
                $junkRef = null;
                $relatedSchema = $property->getRefSchema();

                foreach ($relatedSchema->getProperties() as $prop) {
                    if (!$prop->hasRefItems()) {
                        continue;
                    }
                    if ($schemaName === $prop->getRefSchemaName()) {
                        $junkRef = $prop->getName();
                        break;
                    }
                }
                if ($junkRef) {
                    $relatedTableName = $relatedSchema->resolveTableName($property->getRefClassName());
                    $foreignPkProperty = $property->getTargetProperty();
                    if ($foreignPkProperty === null) {
                        //Non-db
                        break;
                    }

                    $propertyMap[] = [
                        'property' => $property->getName(),
                        'targetClass' => $property->getRefClassName(),
                        'refProperty' => $junkRef,
                        'junctionSchema' => $schemaName,
                        'junctionTable' => $tableName,
                        'relatedClassName' => $property->getRefClassName(),
                        'relatedTableName' => $relatedTableName,
                        'foreignPk' => $foreignPkProperty->getName(),
                        'phpType' => $foreignPkProperty->guessPhpType(),
                        'dbType' => $foreignPkProperty->guessDbType(true),
                    ];
                }

                if (count($propertyMap) === 2) {
                    break;
                }
            }
            if (count($propertyMap) !== 2) {
                throw new Exception('Junction table must contains 2 attributes referenced on other schemas');
            }
            $junkRef0 = $propertyMap[0]['refProperty'];
            $junkRef1 = $propertyMap[1]['refProperty'];
            $propertyMap[0]['class'] = $propertyMap[1]['targetClass'];
            $propertyMap[0]['pairProperty'] = $propertyMap[1]['property'];
            $propertyMap[0]['refProperty'] = $junkRef1;
            $propertyMap[1]['class'] = $propertyMap[0]['targetClass'];
            $propertyMap[1]['refProperty'] = $junkRef0;
            $propertyMap[1]['pairProperty'] = $propertyMap[0]['property'];
            $junctions[] = $propertyMap[0];
            $junctions[] = $propertyMap[1];
            unset($junkRef, $junkRef1, $junkRef0, $propertyMap);
        }
        return Yii::createObject(JunctionSchemas::class, [$junctions]);
    }

    private function canGenerateModel(string $schemaName, ComponentSchema $schema):bool
    {
        // only generate tables for schemas of type object and those who have defined properties
        if ($schema->isObjectSchema() && !$schema->hasProperties()) {
            return false;
        }
        if (!$schema->isObjectSchema()) {
            return false;
        }
        // do not generate tables for composite schemas
        if ($schema->isCompositeSchema()) {
            return false;
        }
        // skip excluded model names
        if (in_array($schemaName, $this->config->excludeModels, true)) {
            return false;
        }

        // skip schemas started with underscore
        if ($this->config->skipUnderscoredSchemas && StringHelper::startsWith($schemaName, '_')) {
            return false;
        }

        if ($this->config->generateModelsOnlyXTable && !$schema->hasCustomTableName()) {
            return false;
        }
        return true;
    }
}

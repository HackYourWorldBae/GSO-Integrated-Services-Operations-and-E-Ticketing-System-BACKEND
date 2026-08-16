<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CheckModels extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:check_models';
    protected $description = 'Checks if all table columns match the model allowedFields';

    public function run(array $params)
    {
        $db = \Config\Database::connect();
        $modelsPath = APPPATH . 'Models';
        
        $files = scandir($modelsPath);
        $inconsistencies = [];

        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $className = 'App\\Models\\' . pathinfo($file, PATHINFO_FILENAME);
                if (class_exists($className)) {
                    $model = new $className();
                    if (method_exists($model, 'getAllowedFields')) {
                        $table = $model->getTable();
                        
                        $reflection = new \ReflectionClass($className);
                        $allowedFields = [];
                        if ($reflection->hasProperty('allowedFields')) {
                            $prop = $reflection->getProperty('allowedFields');
                            $prop->setAccessible(true);
                            $allowedFields = $prop->getValue($model) ?? [];
                        }
                        
                        if ($table && $db->tableExists($table)) {
                            $dbFields = $db->getFieldNames($table);
                            $primaryKey = $model->getPrimaryKey();
                            
                            if (!in_array($primaryKey, $allowedFields)) {
                                $dbFields = array_diff($dbFields, [$primaryKey]);
                            }
                            if ($model->useTimestamps) {
                                $dbFields = array_diff($dbFields, [$model->createdField, $model->updatedField, $model->deletedField]);
                            }
                            
                            $missingInModel = array_diff($dbFields, $allowedFields);
                            $missingInDb = array_diff($allowedFields, $dbFields);
                            
                            if (!empty($missingInModel) || !empty($missingInDb)) {
                                $inconsistencies[$className] = [
                                    'table' => $table,
                                    'missingInModel' => array_values($missingInModel),
                                    'missingInDb' => array_values($missingInDb)
                                ];
                            }
                        }
                    }
                }
            }
        }
        
        CLI::write(json_encode($inconsistencies, JSON_PRETTY_PRINT));
    }
}

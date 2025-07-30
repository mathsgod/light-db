<?php

require_once 'vendor/autoload.php';
require_once 'tests/fixtures/TestModels.php';

// 檢查 Testing 表中的數據
try {
    $query = Testing::Query();
    $records = $query->toArray();
    
    echo "Total records: " . count($records) . PHP_EOL;
    
    foreach ($records as $record) {
        echo "ID: {$record->testing_id}, Name: {$record->name}" . PHP_EOL;
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}

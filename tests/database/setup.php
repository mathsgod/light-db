<?php

declare(strict_types=1);

/**
 * Database setup for testing
 * This will create the necessary test tables with proper structure
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;
use Laminas\Db\Adapter\Adapter;

// Load environment
Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

// Database configuration
$config = [
    'driver' => $_ENV['DATABASE_DRIVER'] ?? 'Pdo_Mysql',
    'database' => $_ENV['DATABASE_DATABASE'] ?? 'test_db',
    'username' => $_ENV['DATABASE_USERNAME'] ?? 'root',
    'password' => $_ENV['DATABASE_PASSWORD'] ?? '',
    'hostname' => $_ENV['DATABASE_HOSTNAME'] ?? 'localhost',
    'port' => $_ENV['DATABASE_PORT'] ?? '3306',
    'charset' => $_ENV['DATABASE_CHARSET'] ?? 'utf8mb4',
];

$adapter = new Adapter($config);

// Drop and create Testing table
echo "Setting up Testing table...\n";

$dropSql = "DROP TABLE IF EXISTS `Testing`";
$adapter->query($dropSql, Adapter::QUERY_MODE_EXECUTE);

$createTestingSql = "
CREATE TABLE `Testing` (
    `testing_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) DEFAULT NULL COMMENT '名稱',
    `email` varchar(255) DEFAULT NULL COMMENT '電子郵件',
    `age` int(11) DEFAULT NULL COMMENT '年齡',
    `score` decimal(5,2) DEFAULT NULL COMMENT '分數',
    `is_active` tinyint(1) DEFAULT 1 COMMENT '是否啟用',
    `birth_date` date DEFAULT NULL COMMENT '出生日期',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
    `j` json DEFAULT NULL COMMENT 'JSON 資料',
    `tags` text DEFAULT NULL COMMENT '標籤（逗號分隔）',
    `description` text DEFAULT NULL COMMENT '描述',
    `status` enum('active','inactive','pending') DEFAULT 'active' COMMENT '狀態',
    `salary` float DEFAULT NULL COMMENT '薪資',
    PRIMARY KEY (`testing_id`),
    KEY `idx_name` (`name`),
    KEY `idx_email` (`email`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='測試用表格';
";

$adapter->query($createTestingSql, Adapter::QUERY_MODE_EXECUTE);
echo "Testing table created successfully!\n";

// Drop and create Testing2 table
echo "Setting up Testing2 table...\n";

$dropSql2 = "DROP TABLE IF EXISTS `Testing2`";
$adapter->query($dropSql2, Adapter::QUERY_MODE_EXECUTE);

$createTesting2Sql = "
CREATE TABLE `Testing2` (
    `testing2_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) DEFAULT NULL,
    `b` tinyint(1) DEFAULT NULL COMMENT '布林值',
    `int_null` int(11) DEFAULT NULL COMMENT '可為空的整數',
    `null_field` varchar(255) DEFAULT NULL COMMENT '可為空欄位',
    `not_null_field` varchar(255) NOT NULL DEFAULT '' COMMENT '不可為空欄位',
    `json_data` json DEFAULT NULL COMMENT 'JSON 資料',
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`testing2_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='測試用表格2';
";

$adapter->query($createTesting2Sql, Adapter::QUERY_MODE_EXECUTE);
echo "Testing2 table created successfully!\n";

// Drop and create Testing3 table
echo "Setting up Testing3 table...\n";

$dropSql3 = "DROP TABLE IF EXISTS `Testing3`";
$adapter->query($dropSql3, Adapter::QUERY_MODE_EXECUTE);

$createTesting3Sql = "
CREATE TABLE `Testing3` (
    `testing3_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(100) DEFAULT NULL,
    `category` varchar(50) DEFAULT NULL,
    `priority` int(11) DEFAULT 1,
    `metadata` json DEFAULT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`testing3_id`),
    KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='測試用表格3';
";

$adapter->query($createTesting3Sql, Adapter::QUERY_MODE_EXECUTE);
echo "Testing3 table created successfully!\n";

// Insert some basic test data
echo "Inserting basic test data...\n";

$testData = [
    ['name' => 'Test User 1', 'email' => 'test1@example.com', 'age' => 25, 'score' => 85.5, 'is_active' => 1, 'birth_date' => '1998-01-15', 'status' => 'active', 'salary' => 50000.00],
    ['name' => 'Test User 2', 'email' => 'test2@example.com', 'age' => 30, 'score' => 92.3, 'is_active' => 1, 'birth_date' => '1993-05-20', 'status' => 'active', 'salary' => 60000.00],
    ['name' => 'Test User 3', 'email' => 'test3@example.com', 'age' => 28, 'score' => 78.9, 'is_active' => 0, 'birth_date' => '1995-08-10', 'status' => 'inactive', 'salary' => 45000.00],
];

foreach ($testData as $data) {
    $columns = array_keys($data);
    $values = array_values($data);
    $placeholders = str_repeat('?,', count($values) - 1) . '?';
    
    $sql = "INSERT INTO Testing (" . implode(',', $columns) . ") VALUES ($placeholders)";
    $adapter->query($sql, $values);
}

echo "Test data inserted successfully!\n";
echo "Database setup completed!\n";

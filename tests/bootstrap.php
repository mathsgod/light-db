<?php

declare(strict_types=1);

// Error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Load environment
use Dotenv\Dotenv;

require_once(__DIR__ . "/../vendor/autoload.php");

// Load environment variables
Dotenv::createImmutable(__DIR__ . "/..")->safeLoad();

// Setup test database (run only once per test session)
if (!defined('TEST_DB_SETUP_DONE')) {
    echo "Setting up test database...\n";
    require_once(__DIR__ . "/database/setup.php");
    define('TEST_DB_SETUP_DONE', true);
    echo "Test database setup completed!\n";
} else {
    // If already set up, don't run setup again
    echo "Test database already set up, skipping...\n";
}

// Load test fixtures (use our improved test models)
require_once(__DIR__ . "/Model.php");
require_once(__DIR__ . "/fixtures/TestModels.php");

// Load base test case
require_once(__DIR__ . "/BaseTestCase.php");

// Test database setup (if needed)
// You can add database setup logic here

// Global test helpers
function truncateAllTestTables(): void
{
    Testing::_table()->truncate();
    Testing2::_table()->truncate();
    Testing3::_table()->truncate();
}

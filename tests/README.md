# Test Documentation

## Test Structure

The test suite is organized into several categories:

### Unit Tests (`tests/Unit/`)
- **ModelTest.php**: Core model functionality, CRUD operations, JSON handling
- **QueryTest.php**: Query building, filtering, aggregation, pagination
- **TableTest.php**: Direct table operations and metadata
- **ErrorHandlingTest.php**: Error conditions and edge cases

### Integration Tests (`tests/Integration/`)
- **RelationshipTest.php**: Model relationships and complex scenarios

### Test Fixtures (`tests/fixtures/`)
- **TestModels.php**: Test model definitions

## Test Groups

Tests are organized with PHPUnit groups for selective execution:

- `@group crud`: Basic CRUD operations
- `@group json`: JSON column functionality
- `@group state`: Dirty tracking and change detection
- `@group validation`: Data validation and type conversion
- `@group query_basic`: Basic query functionality
- `@group query_filters`: Advanced filtering
- `@group relationships`: Model relationships
- `@group performance`: Performance-related tests
- `@group error_handling`: Error conditions

## Running Tests

### All Tests
```bash
composer test
# or
vendor/bin/phpunit
```

### Specific Groups
```bash
vendor/bin/phpunit --group crud
vendor/bin/phpunit --group json
vendor/bin/phpunit --group query_filters
```

### Specific Test Files
```bash
vendor/bin/phpunit tests/Unit/ModelTest.php
vendor/bin/phpunit tests/Unit/QueryTest.php
```

### With Coverage Report
```bash
vendor/bin/phpunit --coverage-html coverage-report
```

## Test Data Management

- Each test class extends `BaseTestCase` which handles cleanup
- Test data is automatically cleaned up after each test
- Helper methods are available for creating test data
- Isolation is maintained between tests

## Best Practices Used

1. **Data Providers**: Used for testing multiple scenarios with same logic
2. **Test Groups**: Allow selective test execution
3. **Proper Assertions**: Specific assertions for better error messages
4. **Edge Case Testing**: Comprehensive coverage of edge cases
5. **Performance Testing**: Basic performance validation
6. **Error Handling**: Tests for error conditions
7. **Clean Architecture**: Separation of concerns in test organization

## Coverage Goals

The test suite aims to cover:
- ✅ All public methods of Model class
- ✅ All query operations and filters
- ✅ JSON column operations
- ✅ Relationship handling
- ✅ Error conditions
- ✅ Performance characteristics
- ✅ Edge cases and boundary conditions

## Adding New Tests

When adding new tests:
1. Extend `BaseTestCase` for automatic cleanup
2. Use appropriate test groups
3. Follow naming conventions (`test*` methods)
4. Include docblocks with `@group` annotations
5. Add data providers for multiple test scenarios
6. Test both positive and negative cases

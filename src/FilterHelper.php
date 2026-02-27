<?php

namespace Light\Db;

class FilterHelper
{
    public static function processFilterValue($fieldExpression, $value)
    {
        $predicate = new \Laminas\Db\Sql\Predicate\Predicate();

        if (is_array($value)) {
            // 檢查是否包含特殊的邏輯操作符
            if (isset($value['_or'])) {
                // 處理 OR 邏輯
                self::processLogicalOperator($predicate, $fieldExpression, $value['_or'], false); // false = OR
            } elseif (isset($value['_and'])) {
                // 處理 AND 邏輯
                self::processLogicalOperator($predicate, $fieldExpression, $value['_and'], true); // true = AND
            } elseif (array_values($value) === $value) {
                // 多個條件的陣列，預設用 AND 連接
                self::processLogicalOperator($predicate, $fieldExpression, $value, true); // true = AND
            } else {
                // 單一條件物件
                foreach ($value as $operator => $operand) {
                    self::applyOperator($predicate, $fieldExpression, $operator, $operand);
                }
            }
        } else {
            $predicate->equalTo($fieldExpression, $value);
        }

        return $predicate;
    }

    private static function processLogicalOperator($predicate, $fieldExpression, $conditions, $useAnd = true)
    {
        $logicalPredicate = $predicate->nest();
        $isFirst = true;

        foreach ($conditions as $condition) {
            if (!$isFirst) {
                $logicalPredicate = $useAnd ? $logicalPredicate->and : $logicalPredicate->or;
            }

            if (is_array($condition)) {
                foreach ($condition as $operator => $operand) {
                    self::applyOperator($logicalPredicate, $fieldExpression, $operator, $operand);
                }
            } else {
                // 如果是簡單值，當作等於處理
                self::applyOperator($logicalPredicate, $fieldExpression, '_eq', $condition);
            }
            $isFirst = false;
        }
        $logicalPredicate->unnest();
    }

    private static function escapeLikeValue(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    private static function applyOperator($where, $field, $operator, $value)
    {
        switch ($operator) {
            case '_like':
            case 'like':
                $where->like($field, $value);
                break;
            case '_contains':
            case 'contains':
                $escaped = self::escapeLikeValue($value);
                $where->like($field, "%$escaped%");
                break;
            case '_startsWith':
            case 'startsWith':
                $escaped = self::escapeLikeValue($value);
                $where->like($field, "$escaped%");
                break;
            case '_endsWith':
            case 'endsWith':
                $escaped = self::escapeLikeValue($value);
                $where->like($field, "%$escaped");
                break;
            case '_in':
            case 'in':
                $where->in($field, $value);
                break;
            case '_notIn':
            case 'notIn':
                $where->notIn($field, $value);
                break;
            case '_eq':
            case 'eq':
                $where->equalTo($field, $value);
                break;
            case '_ne':
            case 'ne':
                $where->notEqualTo($field, $value);
                break;
            case '_gt':
            case 'gt':
                $where->greaterThan($field, $value);
                break;
            case '_gte':
            case 'gte':
                $where->greaterThanOrEqualTo($field, $value);
                break;
            case '_lt':
            case 'lt':
                $where->lessThan($field, $value);
                break;
            case '_lte':
            case 'lte':
                $where->lessThanOrEqualTo($field, $value);
                break;
            case '_between':
            case 'between':
                $where->between($field, $value[0], $value[1]);
                break;
            case '_notBetween':
            case 'notBetween':
                $where->notBetween($field, $value[0], $value[1]);
                break;
            case '_null':
            case 'null':
                $where->isNull($field);
                break;
            case '_notNull':
            case 'notNull':                
                $where->isNotNull($field);
                break;
        }
    }
}

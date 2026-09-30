<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterCombinator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\Filter\FilterOperator;
use NeuronAI\RAG\VectorStore\Filter\FilterValidator;

/** @internal */
final class ParadeDBFilterCompiler
{
    public function __construct(private readonly DocumentSchema $schema)
    {
    }

    /** @return array{sql: string, bindings: array<string, string>} */
    public function compile(?FilterExpression $filters, string $prefix = 'f'): array
    {
        if ($filters === null) {
            return ['sql' => 'TRUE', 'bindings' => []];
        }
        (new FilterValidator())->validate($filters, $this->schema);
        $bindings = [];
        $bind = static function (string $value) use (&$bindings, $prefix): string {
            $placeholder = ':'.$prefix.count($bindings);
            $bindings[$placeholder] = $value;

            return $placeholder;
        };

        return ['sql' => $this->expression($filters, $bind), 'bindings' => $bindings];
    }

    /** @param callable(string): string $bind */
    private function expression(FilterExpression $expression, callable $bind): string
    {
        if ($expression instanceof FilterGroup) {
            $parts = array_map(fn (FilterExpression $child): string => $this->expression($child, $bind), $expression->conditions());

            return '('.implode($expression->operator() === FilterCombinator::And ? ' AND ' : ' OR ', $parts).')';
        }
        if (!$expression instanceof Filter) {
            throw new VectorStoreException('ParadeDB supports portable filters only; raw filters are not supported.');
        }

        $sourceColumn = match ($expression->field) {
            'sourceType' => 'source_type',
            'sourceName' => 'source_name',
            default => null,
        };
        $column = $sourceColumn ?? '(metadata -> CAST('.$bind($expression->field).' AS text))';
        $values = in_array($expression->operator, [FilterOperator::In, FilterOperator::ContainsAny, FilterOperator::ContainsAll], true)
            ? (array) $expression->value : [$expression->value];
        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = $sourceColumn !== null
                ? $bind((string) $value)
                : 'CAST('.$bind(json_encode($value, JSON_THROW_ON_ERROR)).' AS jsonb)';
        }

        if (in_array($expression->operator, [FilterOperator::ContainsAny, FilterOperator::ContainsAll], true)) {
            // Bind the key separately for each occurrence: native PDO cannot reuse placeholders.
            $parts = [];
            foreach ($placeholders as $index => $placeholder) {
                $operand = $index === 0 ? $column : '(metadata -> CAST('.$bind($expression->field).' AS text))';
                $parts[] = $operand.' @> jsonb_build_array('.$placeholder.')';
            }

            return '('.implode($expression->operator === FilterOperator::ContainsAll ? ' AND ' : ' OR ', $parts).')';
        }

        $operator = match ($expression->operator) {
            FilterOperator::Eq => '=',
            FilterOperator::Neq => '<>',
            FilterOperator::Gt => '>',
            FilterOperator::Gte => '>=',
            FilterOperator::Lt => '<',
            FilterOperator::Lte => '<=',
            FilterOperator::In => 'IN',
        };
        $sql = $column.' '.$operator.' '.($expression->operator === FilterOperator::In ? '('.implode(', ', $placeholders).')' : $placeholders[0]);
        if (in_array($expression->operator, [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte], true)) {
            $sql .= " AND jsonb_typeof(metadata -> CAST(".$bind($expression->field)." AS text)) = 'number'";
        }

        return '('.$sql.')';
    }
}

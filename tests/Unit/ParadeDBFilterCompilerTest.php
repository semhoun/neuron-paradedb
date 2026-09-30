<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\Tests\Unit;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use PHPUnit\Framework\TestCase;
use Semhoun\NeuronParadeDB\VectorStore\ParadeDBFilterCompiler;
use Semhoun\NeuronParadeDB\VectorStore\ParadeDBVectorStore;

final class ParadeDBFilterCompilerTest extends TestCase
{
    public function testCompilesAllOperatorsWithUniqueBindingsAndNoInterpolatedValues(): void
    {
        $schema = DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::boolean('active')->filterable(),
            DocumentField::strings('tags')->filterable(),
        );
        $payload = "O'Reilly'; DROP TABLE documents; --";
        $filters = FilterGroup::allOf(
            Filter::eq('tenant', $payload),
            Filter::neq('tenant', 'other'),
            Filter::in('tenant', ['A', 'B']),
            Filter::eq('active', false),
            Filter::gt('year', 2020),
            Filter::gte('year', 2021),
            Filter::lt('year', 2030),
            Filter::lte('year', 2029),
            Filter::containsAny('tags', ['one', 'two']),
            Filter::containsAll('tags', ['a', 'b']),
            FilterGroup::anyOf(Filter::eq('sourceType', 'web'), Filter::eq('sourceName', $payload)),
        );
        $compiled = (new ParadeDBFilterCompiler($schema))->compile($filters);
        self::assertStringNotContainsString($payload, $compiled['sql']);
        self::assertContains($payload, $compiled['bindings']);
        self::assertContains(json_encode($payload, JSON_THROW_ON_ERROR), $compiled['bindings']);
        self::assertContains('false', $compiled['bindings']);
        foreach ([' = ', ' <> ', ' IN ', ' > ', ' >= ', ' < ', ' <= ', ' @> ', ' OR ', ' AND ', 'source_type', 'source_name'] as $operator) {
            self::assertStringContainsString($operator, $compiled['sql']);
        }
        preg_match_all('/:f\d+\b/', $compiled['sql'], $matches);
        self::assertCount(count($compiled['bindings']), $matches[0]);
        self::assertCount(count($compiled['bindings']), array_unique($matches[0]));
        self::assertEqualsCanonicalizing(array_keys($compiled['bindings']), $matches[0]);
    }

    public function testRejectsInvalidAndRawFiltersIncludingNestedBranches(): void
    {
        $compiler = new ParadeDBFilterCompiler(DocumentSchema::of(
            DocumentField::string('optional')->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::string('private'),
        ));
        $unknown = new class () implements FilterExpression {
            public function toArray(): array
            {
                return [];
            }
        };
        foreach ([Filter::eq('id', 'x'), Filter::eq('content', 'x'), Filter::eq('private', 'x'),
            Filter::eq('year', '2020'), Filter::neq('optional', 'x'), Filter::gt('sourceName', 1),
            Filter::containsAny('year', ['x']), Filter::eq("x') OR TRUE --", 'x'),
            Filter::raw(ParadeDBVectorStore::class, 'TRUE'), $unknown,
            FilterGroup::anyOf(Filter::eq('sourceType', 'web'), Filter::raw(ParadeDBVectorStore::class, 'TRUE'))] as $filter) {
            try {
                $compiler->compile($filter);
                self::fail('Invalid filters must not compile.');
            } catch (VectorStoreException|\NeuronAI\RAG\Schema\DocumentSchemaException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }
}

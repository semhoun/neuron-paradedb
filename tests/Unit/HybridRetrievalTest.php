<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\Tests\Unit;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterScope;
use PHPUnit\Framework\TestCase;
use Semhoun\NeuronParadeDB\Retrieval\HybridRetrieval;
use Semhoun\NeuronParadeDB\VectorStore\HybridVectorStoreInterface;

final class HybridRetrievalTest extends TestCase
{
    public function testMandatoryFiltersAreAndedWithOwnScopeAndDoNotLeak(): void
    {
        $own = Filter::eq('sourceType', 'web');
        $incoming = Filter::where('tenant', 'A')->whereAny(Filter::eq('status', 'public'), Filter::eq('status', 'review'));
        $provider = $this->createStub(EmbeddingsProviderInterface::class);
        $provider->method('embedText')->willReturn([1.0]);
        $store = $this->createMock(HybridVectorStoreInterface::class);
        $seen = [];
        $store->expects(self::exactly(2))->method('hybridSearch')
            ->willReturnCallback(static function (string $query, array $embedding, ?FilterExpression $filters) use (&$seen): array {
                $seen[] = $filters?->toArray();
                return [];
            });
        $retrieval = new HybridRetrieval($store, $provider, $own);
        $retrieval->retrieve(new UserMessage('query'), $incoming);
        $retrieval->retrieve(new UserMessage('query'));
        self::assertSame([FilterScope::merge($own, $incoming)->expression()->toArray(), $own->toArray()], $seen);
    }

    public function testPassesExactQueryAndEmbeddingAndMaterializesGenerator(): void
    {
        $query = "  Exact 'query' + punctuation?\nsecond line  ";
        $embedding = [0.125, -0.5, 1.0];
        $first = new Document('first');
        $second = new Document('second');
        $provider = $this->createMock(EmbeddingsProviderInterface::class);
        $provider->expects(self::once())->method('embedText')->with(self::identicalTo($query))
            ->willReturn($embedding);
        $provider->expects(self::never())->method('embedDocument');
        $provider->expects(self::never())->method('embedDocuments');
        $store = $this->createMock(HybridVectorStoreInterface::class);
        $store->expects(self::never())->method('search');
        $store->expects(self::once())->method('hybridSearch')
            ->with(self::identicalTo($query), self::identicalTo($embedding))
            ->willReturn((static function () use ($first, $second): \Generator {
                yield $first;
                yield $second;
            })());

        self::assertSame([$first, $second], (new HybridRetrieval($store, $provider))->retrieve(new UserMessage($query)));
    }

    public function testReturnsEmptyArrayForEmptyGenerator(): void
    {
        $provider = $this->createMock(EmbeddingsProviderInterface::class);
        $provider->expects(self::once())->method('embedText')->with('unmatched')->willReturn([1.0]);
        $store = $this->createMock(HybridVectorStoreInterface::class);
        $store->expects(self::once())->method('hybridSearch')->with('unmatched', [1.0])
            ->willReturn((static function (): \Generator {
                yield from [];
            })());

        self::assertSame([], (new HybridRetrieval($store, $provider))->retrieve(new UserMessage('unmatched')));
    }
}

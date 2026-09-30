<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

interface HybridVectorStoreInterface extends VectorStoreInterface
{
    /**
     * @param float[] $embedding
     * @return iterable<Document>
     */
    public function hybridSearch(string $query, array $embedding, ?FilterExpression $filters = null, ?int $topK = null): iterable;
}

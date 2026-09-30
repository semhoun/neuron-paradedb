<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\Retrieval;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterScope;
use Semhoun\NeuronParadeDB\VectorStore\HybridVectorStoreInterface;

class HybridRetrieval implements RetrievalInterface
{
    public function __construct(
        protected readonly HybridVectorStoreInterface $vectorStore,
        protected readonly EmbeddingsProviderInterface $embeddingProvider,
        protected readonly ?FilterExpression $filters = null,
    ) {
    }

    /** @return Document[] */
    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        $content = $query->getContent();
        if ($content === null) {
            throw new InvalidArgumentException('Hybrid retrieval requires a message with text content.');
        }

        $documents = $this->vectorStore->hybridSearch(
            $content,
            $this->embeddingProvider->embedText($content),
            FilterScope::merge($this->filters, $filters)?->expression(),
        );

        return is_array($documents) ? array_values($documents) : iterator_to_array($documents, false);
    }
}

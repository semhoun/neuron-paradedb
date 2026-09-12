<?php

declare(strict_types=1);

namespace Semhoun\NeuronParadeDB\Retrieval;

use InvalidArgumentException;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use Semhoun\NeuronParadeDB\VectorStore\HybridVectorStoreInterface;

class HybridRetrieval implements RetrievalInterface
{
    public function __construct(
        protected readonly HybridVectorStoreInterface $vectorStore,
        protected readonly EmbeddingsProviderInterface $embeddingProvider,
    ) {
    }

    /** @return Document[] */
    public function retrieve(Message $query): array
    {
        $content = $query->getContent();
        if ($content === null) {
            throw new InvalidArgumentException('Hybrid retrieval requires a message with text content.');
        }

        $documents = $this->vectorStore->hybridSearch(
            $content,
            $this->embeddingProvider->embedText($content),
        );

        return is_array($documents) ? array_values($documents) : iterator_to_array($documents, false);
    }
}

<?php

declare(strict_types=1);

namespace App\Rag;

use RuntimeException;

/**
 * The markdown an agent is given to reason with.
 *
 * Retrieval here is the whole file, not the top-k chunks of it, and that is a
 * decision rather than a shortcut: the practice-area guide describes 24
 * mutually exclusive options in a few kilobytes, so similarity search could
 * only ever remove candidates — and it would remove them precisely when the
 * narrative is vague, which is the case that needs every option on the table.
 * Embedding-backed retrieval earns its keep against a corpus too large to pass
 * whole, which is what the Jurisprudência module will be.
 *
 * Memoized per process because an agent's instructions are rebuilt on every
 * prompt, and the file does not change between them.
 */
final class KnowledgeBase
{
    /** @var array<string, string> */
    private array $loaded = [];

    /**
     * Get the contents of a knowledge document by name, without the extension.
     *
     * @throws RuntimeException if the document does not exist.
     */
    public function get(string $name): string
    {
        return $this->loaded[$name] ??= $this->read($name);
    }

    private function read(string $name): string
    {
        $path = __DIR__.'/knowledge/'.$name.'.md';

        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("Documento de conhecimento ausente: {$name}.md");
        }

        return trim($contents);
    }
}

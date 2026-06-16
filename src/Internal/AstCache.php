<?php

declare(strict_types=1);

namespace Componenta\VarExport\Internal;

use Componenta\VarExport\Exception\ClosureExportException;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;

/**
 * Caches parsed AST to avoid re-parsing the same file multiple times.
 *
 * Uses file modification time for cache invalidation and LRU eviction.
 *
 * @internal This class is not part of the public API
 */
final class AstCache
{
    /**
     * @var array<string, array{mtime: int, ast: array<Stmt>}>
     */
    private array $cache = [];

    private ?Parser $parser = null;

    /**
     * Maximum number of cached entries to prevent memory issues.
     */
    private int $maxEntries;

    public function __construct(int $maxEntries = 32)
    {
        $this->maxEntries = max(1, $maxEntries);
    }

    /**
     * Get parsed AST for a file, using cache if available.
     *
     * @return array<Stmt>
     * @throws ClosureExportException If file cannot be read or parsed
     */
    public function getAst(string $filename): array
    {
        $mtime = $this->getFileMtime($filename);
        $key = $filename;

        if (isset($this->cache[$key]) && $this->cache[$key]['mtime'] === $mtime) {
            // Move to end for LRU (most recently used)
            $entry = $this->cache[$key];
            unset($this->cache[$key]);
            $this->cache[$key] = $entry;

            return $entry['ast'];
        }

        $ast = $this->parseFile($filename);
        $this->storeInCache($key, $mtime, $ast);

        return $ast;
    }

    /**
     * Clear the cache.
     */
    public function clear(): void
    {
        $this->cache = [];
    }

    /**
     * Get current cache size.
     */
    public function size(): int
    {
        return count($this->cache);
    }

    /**
     * Get file modification time.
     *
     * @throws ClosureExportException If file does not exist
     */
    private function getFileMtime(string $filename): int
    {
        $mtime = @filemtime($filename);

        if ($mtime === false) {
            throw ClosureExportException::sourceNotFound($filename);
        }

        return $mtime;
    }

    /**
     * Parse a PHP file into AST.
     *
     * @return array<Stmt>
     * @throws ClosureExportException If parsing fails
     */
    private function parseFile(string $filename): array
    {
        $code = @file_get_contents($filename);

        if ($code === false) {
            throw ClosureExportException::sourceUnreadable($filename);
        }

        $parser = $this->getParser();

        try {
            $ast = $parser->parse($code);
        } catch (\Throwable $e) {
            throw ClosureExportException::parsingFailed($filename, $e->getMessage());
        }

        if ($ast === null) {
            throw ClosureExportException::parsingFailed($filename, 'Parser returned null');
        }

        // Resolve names once per file: class references become fully qualified,
        // while unqualified function/constant references in a namespace keep
        // their original form so PHP's runtime fallback to the global namespace
        // still applies when the exported closure is evaluated elsewhere.
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        return $traverser->traverse($ast);
    }

    /**
     * Get or create parser instance.
     */
    private function getParser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForVersion(
            PhpVersion::getHostVersion(),
        );
    }

    /**
     * Store entry in cache with LRU eviction.
     *
     * @param array<Stmt> $ast
     */
    private function storeInCache(string $key, int $mtime, array $ast): void
    {
        // Drop any stale record for this key first, otherwise an unrelated
        // LRU slot might be evicted while the outdated entry lingers at the
        // tail of the map.
        unset($this->cache[$key]);

        if (count($this->cache) >= $this->maxEntries) {
            $oldestKey = array_key_first($this->cache);
            if ($oldestKey !== null) {
                unset($this->cache[$oldestKey]);
            }
        }

        $this->cache[$key] = ['mtime' => $mtime, 'ast' => $ast];
    }
}

<?php

declare(strict_types=1);

namespace Componenta\VarExport\Source;

use Componenta\VarExport\Contract\ClosureSourceCacheInterface;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Exception\ConfigurationException;
use Componenta\VarExport\Internal\ClosureIndexingVisitor;
use Componenta\VarExport\Internal\WarningGuard;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Throwable;

/**
 * Content-addressed LRU cache for parsed closure source metadata.
 *
 * The cache never exposes its mutable AST nodes directly: candidates() returns
 * deep-cloned subtrees that callers may safely transform.
 */
class ClosureSourceCache implements ClosureSourceCacheInterface
{
    /**
     * @var array<string, array{
     *     hash: string,
     *     sourceBytes: int,
     *     byLine: array<int, list<ClosureSourceCandidate>>
     * }>
     */
    private array $cache = [];

    private ?Parser $parser = null;
    private int $cachedSourceBytes = 0;

    public function __construct(
        private readonly int $maxEntries = 32,
        private readonly int $maxSourceBytes = 8_388_608,
        private readonly int $maxCachedSourceBytes = 33_554_432,
    ) {
        foreach ([
            'maxEntries' => $maxEntries,
            'maxSourceBytes' => $maxSourceBytes,
            'maxCachedSourceBytes' => $maxCachedSourceBytes,
        ] as $name => $value) {
            if ($value < 1) {
                throw ConfigurationException::invalidCacheLimit($name, $value);
            }
        }
    }

    public function candidates(string $filename, int $startLine): array
    {
        if ($startLine < 1) {
            return [];
        }

        $path = self::canonicalPath($filename);
        $source = $this->readSource($path);
        $sourceBytes = strlen($source);
        $hash = hash('sha256', $source);
        $entry = $this->cache[$path] ?? null;

        if ($entry === null || !hash_equals($entry['hash'], $hash)) {
            $entry = [
                'hash' => $hash,
                'sourceBytes' => $sourceBytes,
                'byLine' => $this->parseAndIndex($path, $source),
            ];
            $this->store($path, $entry);
        } else {
            $this->touch($path, $entry);
        }

        $result = [];
        foreach ($entry['byLine'][$startLine] ?? [] as $candidate) {
            $result[] = $candidate->detached();
        }

        return $result;
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->cachedSourceBytes = 0;
    }

    public function size(): int
    {
        return count($this->cache);
    }

    private function readSource(string $path): string
    {
        $stream = WarningGuard::run(static fn() => fopen($path, 'rb'));
        if (!is_resource($stream)) {
            if (!file_exists($path)) {
                throw ClosureExportException::sourceNotFound($path);
            }

            throw ClosureExportException::sourceUnreadable($path);
        }

        try {
            $readLimit = $this->maxSourceBytes === PHP_INT_MAX
                ? PHP_INT_MAX
                : $this->maxSourceBytes + 1;
            $source = WarningGuard::run(
                static fn(): string|false => stream_get_contents($stream, $readLimit),
            );
        } finally {
            WarningGuard::run(static fn(): bool => fclose($stream));
        }

        if ($source === false) {
            throw ClosureExportException::sourceUnreadable($path);
        }

        $sourceBytes = strlen($source);
        if ($sourceBytes > $this->maxSourceBytes) {
            throw ClosureExportException::sourceTooLarge(
                $path,
                $sourceBytes,
                $this->maxSourceBytes,
            );
        }

        return $source;
    }

    /**
     * @return array<int, list<ClosureSourceCandidate>>
     */
    private function parseAndIndex(string $filename, string $source): array
    {
        $parser = $this->getParser();
        try {
            $ast = $parser->parse($source);
        } catch (Throwable $e) {
            throw ClosureExportException::parsingFailed($filename, $e->getMessage(), $e);
        }

        if ($ast === null) {
            throw ClosureExportException::parsingFailed($filename, 'Parser returned null.');
        }

        $resolver = new NodeTraverser(new NameResolver());
        /** @var array<Stmt> $resolved */
        $resolved = $resolver->traverse($ast);

        $visitor = new ClosureIndexingVisitor($parser->getTokens());
        (new NodeTraverser($visitor))->traverse($resolved);

        return $visitor->candidatesByLine();
    }

    private function getParser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForVersion(PhpVersion::getHostVersion());
    }

    /**
     * @param array{hash: string, sourceBytes: int, byLine: array<int, list<ClosureSourceCandidate>>} $entry
     */
    private function store(string $path, array $entry): void
    {
        if (isset($this->cache[$path])) {
            $this->cachedSourceBytes -= $this->cache[$path]['sourceBytes'];
            unset($this->cache[$path]);
        }

        while (
            $this->cache !== []
            && (count($this->cache) >= $this->maxEntries
                || $this->cachedSourceBytes + $entry['sourceBytes'] > $this->maxCachedSourceBytes)
        ) {
            $this->evictOldest();
        }

        // A source may be allowed as a one-off parse while intentionally too
        // large for the aggregate cache budget. In that case do not retain it.
        if ($entry['sourceBytes'] > $this->maxCachedSourceBytes) {
            return;
        }

        $this->cache[$path] = $entry;
        $this->cachedSourceBytes += $entry['sourceBytes'];
    }

    /**
     * @param array{hash: string, sourceBytes: int, byLine: array<int, list<ClosureSourceCandidate>>} $entry
     */
    private function touch(string $path, array $entry): void
    {
        unset($this->cache[$path]);
        $this->cache[$path] = $entry;
    }

    private function evictOldest(): void
    {
        $oldest = array_key_first($this->cache);
        if ($oldest === null) {
            return;
        }

        $this->cachedSourceBytes -= $this->cache[$oldest]['sourceBytes'];
        unset($this->cache[$oldest]);
    }

    private static function canonicalPath(string $filename): string
    {
        $real = realpath($filename);

        return $real !== false ? $real : $filename;
    }
}

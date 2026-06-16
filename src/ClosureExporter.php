<?php

declare(strict_types=1);

namespace Componenta\VarExport;

use Closure;
use Componenta\VarExport\Config\ClosureUseMode;
use Componenta\VarExport\Config\ExportConfig;
use Componenta\VarExport\Contract\ClosureExporterInterface;
use Componenta\VarExport\Exception\ClosureExportException;
use Componenta\VarExport\Internal\AstCache;
use Componenta\VarExport\Internal\ClosureValidator;
use Componenta\VarExport\Internal\MagicConstantResolver;
use Componenta\VarExport\Internal\UseVariableInliner;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use ReflectionFunction;

/**
 * Exports PHP closures to their string representation.
 *
 * Uses PHP-Parser for AST analysis to accurately export closure source code
 * with proper namespace resolution and optional use() variable inlining.
 */
final readonly class ClosureExporter implements ClosureExporterInterface
{
    private ClosureValidator $validator;
    private UseVariableInliner $inliner;
    private NodeFinder $nodeFinder;
    private PrettyPrinter $printer;
    private AstCache $astCache;

    public function __construct(
        private ExportConfig $config = new ExportConfig(),
        ?AstCache $astCache = null,
    ) {
        $this->validator = new ClosureValidator();
        $this->inliner = new UseVariableInliner();
        $this->nodeFinder = new NodeFinder();
        // Match the printer's own body indentation to the configured indent
        // so internal statement nesting looks consistent with the rest of
        // the exported output (tabs vs spaces, width, etc.).
        $this->printer = new PrettyPrinter(['indent' => $this->config->indent]);
        $this->astCache = $astCache ?? new AstCache();
    }

    public function export(Closure $closure): string
    {
        return $this->exportWithDepth($closure, 0);
    }

    public function exportWithDepth(Closure $closure, int $depth): string
    {
        $reflection = $this->validator->validate($closure, $this->config->closureUseMode);
        $node = $this->extractClosureNode($reflection);
        $node = $this->applyMagicConstants($node, $reflection);

        if ($this->config->closureUseMode === ClosureUseMode::Inline) {
            $this->assertNoByRefUseInInlineMode($node, $reflection);
            $variables = $reflection->getClosureUsedVariables();
            $node = $this->inliner->inline($node, $variables);
        }

        $code = $this->printer->prettyPrintExpr($node);

        return $this->config->isPretty()
            ? $this->formatPretty($code, $depth)
            : $this->formatCompact($code);
    }

    public function withConfig(ExportConfig $config): static
    {
        return new self($config, $this->astCache);
    }

    /**
     * Extract the closure AST node from source file.
     *
     * @throws ClosureExportException
     */
    private function extractClosureNode(ReflectionFunction $reflection): ClosureNode|ArrowFunction
    {
        $filename = $reflection->getFileName();
        $line = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $paramCount = $reflection->getNumberOfParameters();

        $ast = $this->astCache->getAst($filename);
        $closures = $this->findClosuresOnLine($ast, $line);

        if ($closures === []) {
            throw ClosureExportException::nodeNotFound($line, $filename);
        }

        $originalCount = count($closures);

        if ($originalCount > 1) {
            // Narrow by end line first, then by parameter count. Two closures
            // with identical start line, end line AND arity on the same line
            // are genuinely ambiguous - give up and ask the caller to split
            // them onto separate lines.
            $closures = $this->narrow($closures, static fn($n) => $n->getEndLine() === $endLine);

            if (count($closures) > 1) {
                $closures = $this->narrow(
                    $closures,
                    static fn($n) => count($n->getParams()) === $paramCount,
                );
            }

            if (count($closures) !== 1) {
                throw ClosureExportException::ambiguousLocation($line, $originalCount, $filename);
            }
        }

        return reset($closures);
    }

    /**
     * @param array<ClosureNode|ArrowFunction> $candidates
     * @return array<ClosureNode|ArrowFunction>
     */
    private function narrow(array $candidates, callable $predicate): array
    {
        $filtered = array_values(array_filter($candidates, $predicate));

        // If the predicate eliminates everything it was unreliable for this
        // call (e.g. end-line metadata missing) - keep the previous set so
        // the next predicate still has something to work with.
        return $filtered === [] ? $candidates : $filtered;
    }

    /**
     * Find all closure/arrow function nodes starting on a specific line.
     *
     * @param array<Node> $ast
     * @return array<ClosureNode|ArrowFunction>
     */
    private function findClosuresOnLine(array $ast, int $line): array
    {
        return $this->nodeFinder->find(
            $ast,
            static fn(Node $node) => ($node instanceof ClosureNode || $node instanceof ArrowFunction)
                && $node->getStartLine() === $line,
        );
    }

    /**
     * Substitute file-level magic constants so the closure is self-contained.
     */
    private function applyMagicConstants(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): ClosureNode|ArrowFunction {
        $filename = $reflection->getFileName();
        $namespace = $this->nodeFinder->findFirstInstanceOf(
            $this->astCache->getAst($filename),
            Namespace_::class,
        );

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new MagicConstantResolver($namespace, $filename));

        /** @var array<ClosureNode|ArrowFunction> $result */
        $result = $traverser->traverse([$node]);

        return $result[0];
    }

    /**
     * Collapse whitespace between tokens without touching string/heredoc contents.
     *
     * A naive regex over the printer output would corrupt literal whitespace
     * inside quoted strings; tokenising the output guarantees we only rewrite
     * whitespace that is actually code formatting.
     */
    private function formatCompact(string $code): string
    {
        $tokens = \PhpToken::tokenize('<?php ' . $code);
        $result = '';
        $previousWasSpace = true;

        foreach ($tokens as $index => $token) {
            if ($index === 0 && $token->is(T_OPEN_TAG)) {
                continue;
            }

            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                if (!$previousWasSpace) {
                    $result .= ' ';
                    $previousWasSpace = true;
                }
                continue;
            }

            $result .= $token->text;
            $previousWasSpace = false;
        }

        return trim($result);
    }

    /**
     * Prepend base indent to every code-level newline in the printer output.
     *
     * The PrettyPrinter already emits body statements indented relative to
     * column 0. What we need to add is the caller's nesting level - one
     * copy of the configured indent per depth, inserted after every newline
     * that belongs to code formatting (not to a literal newline inside a
     * string or heredoc).
     *
     * A naive `str_replace("\n", "\n" . $baseIndent, $code)` would also
     * indent the inside of a heredoc body, silently corrupting literal
     * whitespace in user data. Tokenising the output lets us tell those
     * newlines apart: the bytes that live inside a string-like token are
     * pass-through, everything else gets the base indent prepended to
     * every newline.
     */
    private function formatPretty(string $code, int $depth): string
    {
        // Arrow functions and single-line closures need no re-indent -
        // the caller will prepend whatever item indent it chose.
        if (!str_contains($code, "\n")) {
            return $code;
        }

        $baseIndent = str_repeat($this->config->indent, $depth);

        if ($baseIndent === '') {
            return $code;
        }

        $tokens = \PhpToken::tokenize('<?php ' . $code);
        $result = '';

        foreach ($tokens as $index => $token) {
            if ($index === 0 && $token->is(T_OPEN_TAG)) {
                continue;
            }

            if ($this->isStringLiteralToken($token)) {
                $result .= $token->text;
                continue;
            }

            $result .= str_replace("\n", "\n" . $baseIndent, $token->text);
        }

        return $result;
    }

    /**
     * Reject inlining when the closure captures any variable by reference.
     *
     * Inlining replaces the captured variable with a literal value node,
     * which silently strips the reference semantics: mutations inside the
     * closure would stop propagating back to the caller, turning a
     * functional closure into one that looks right but behaves wrong.
     * Better to fail loudly than to ship a subtly-broken closure.
     *
     * Arrow functions have no explicit use clause, so they are never
     * affected by this check.
     */
    private function assertNoByRefUseInInlineMode(
        ClosureNode|ArrowFunction $node,
        ReflectionFunction $reflection,
    ): void {
        if (!$node instanceof ClosureNode) {
            return;
        }

        $byRefVars = [];
        foreach ($node->uses as $use) {
            if ($use->byRef && is_string($use->var->name)) {
                $byRefVars[$use->var->name] = 'captured by reference';
            }
        }

        if ($byRefVars !== []) {
            throw ClosureExportException::cannotInlineUseVariables(
                $byRefVars,
                $reflection->getFileName() ?: null,
                $reflection->getStartLine() ?: null,
            );
        }
    }

    /**
     * Tokens whose text is user-facing string data - their internal
     * newlines belong to the literal value and must not be re-indented.
     */
    private function isStringLiteralToken(\PhpToken $token): bool
    {
        return $token->is([
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_START_HEREDOC,
            T_END_HEREDOC,
            T_INLINE_HTML,
        ]);
    }
}

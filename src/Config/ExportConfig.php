<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

use Componenta\VarExport\Exception\ConfigurationException;

/**
 * Configuration for variable export.
 *
 * Immutable value object that holds all export settings.
 * Use with*() methods to create modified copies.
 */
final readonly class ExportConfig
{
    public const int DEFAULT_MAX_DEPTH = 64;
    public const string DEFAULT_INDENT = '    ';

    /**
     * @throws ConfigurationException If configuration values are invalid
     */
    public function __construct(
        public FormatterMode $mode = FormatterMode::Standard,
        public string $indent = self::DEFAULT_INDENT,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public bool $sortKeys = false,
        public bool $trailingComma = false,
        public ClosureUseMode $closureUseMode = ClosureUseMode::Preserve,
    ) {
        $this->validateIndent($indent);
        $this->validateMaxDepth($maxDepth);
    }

    public function withMode(FormatterMode $mode): self
    {
        return new self(
            $mode,
            $this->indent,
            $this->maxDepth,
            $this->sortKeys,
            $this->trailingComma,
            $this->closureUseMode,
        );
    }

    public function withIndent(string $indent): self
    {
        return new self(
            $this->mode,
            $indent,
            $this->maxDepth,
            $this->sortKeys,
            $this->trailingComma,
            $this->closureUseMode,
        );
    }

    public function withMaxDepth(int $maxDepth): self
    {
        return new self(
            $this->mode,
            $this->indent,
            $maxDepth,
            $this->sortKeys,
            $this->trailingComma,
            $this->closureUseMode,
        );
    }

    public function withSortKeys(bool $sortKeys = true): self
    {
        return new self(
            $this->mode,
            $this->indent,
            $this->maxDepth,
            $sortKeys,
            $this->trailingComma,
            $this->closureUseMode,
        );
    }

    public function withTrailingComma(bool $trailingComma = true): self
    {
        return new self(
            $this->mode,
            $this->indent,
            $this->maxDepth,
            $this->sortKeys,
            $trailingComma,
            $this->closureUseMode,
        );
    }

    public function withClosureUseMode(ClosureUseMode $closureUseMode): self
    {
        return new self(
            $this->mode,
            $this->indent,
            $this->maxDepth,
            $this->sortKeys,
            $this->trailingComma,
            $closureUseMode,
        );
    }

    /**
     * Create configuration for pretty output.
     */
    public static function pretty(): self
    {
        return new self(mode: FormatterMode::Pretty, trailingComma: true);
    }

    /**
     * Create configuration for compact output.
     */
    public static function compact(): self
    {
        return new self(mode: FormatterMode::Standard);
    }

    public function isPretty(): bool
    {
        return $this->mode === FormatterMode::Pretty;
    }

    /**
     * Validate indent string contains only whitespace.
     *
     * @throws ConfigurationException
     */
    private function validateIndent(string $indent): void
    {
        // Indent must be non-empty and contain only spaces or tabs
        if ($indent === '' || !preg_match('/^[ \t]+$/', $indent)) {
            throw ConfigurationException::invalidIndent($indent);
        }
    }

    /**
     * @throws ConfigurationException
     */
    private function validateMaxDepth(int $maxDepth): void
    {
        if ($maxDepth < 1) {
            throw ConfigurationException::invalidMaxDepth($maxDepth);
        }
    }
}

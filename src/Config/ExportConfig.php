<?php

declare(strict_types=1);

namespace Componenta\VarExport\Config;

use Componenta\VarExport\Exception\ConfigurationException;

final readonly class ExportConfig
{
    public const int DEFAULT_MAX_DEPTH = 64;
    public const string DEFAULT_INDENT = '    ';

    /** @throws ConfigurationException */
    public function __construct(
        public FormatterMode $mode = FormatterMode::Standard,
        public string $indent = self::DEFAULT_INDENT,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public bool $sortKeys = false,
        public bool $trailingComma = false,
        public ClosureUseMode $closureUseMode = ClosureUseMode::Preserve,
        public bool $allowGenericReadonlyObjects = false,
        public ClosureExportPolicy $closureExportPolicy = ClosureExportPolicy::SourceBound,
        public SourcePathPolicy $sourcePathPolicy = SourcePathPolicy::AbsoluteBuildPath,
    ) {
        self::assertIndent($indent);

        if ($maxDepth < 1) {
            throw ConfigurationException::invalidMaxDepth($maxDepth);
        }
    }

    public function withMode(FormatterMode $mode): self
    {
        return new self($mode, $this->indent, $this->maxDepth, $this->sortKeys, $this->trailingComma, $this->closureUseMode, $this->allowGenericReadonlyObjects, $this->closureExportPolicy, $this->sourcePathPolicy);
    }

    public function withIndent(string $indent): self
    {
        return new self($this->mode, $indent, $this->maxDepth, $this->sortKeys, $this->trailingComma, $this->closureUseMode, $this->allowGenericReadonlyObjects, $this->closureExportPolicy, $this->sourcePathPolicy);
    }

    public function withMaxDepth(int $maxDepth): self
    {
        return new self($this->mode, $this->indent, $maxDepth, $this->sortKeys, $this->trailingComma, $this->closureUseMode, $this->allowGenericReadonlyObjects, $this->closureExportPolicy, $this->sourcePathPolicy);
    }

    public function withSortKeys(bool $sortKeys = true): self
    {
        return new self($this->mode, $this->indent, $this->maxDepth, $sortKeys, $this->trailingComma, $this->closureUseMode, $this->allowGenericReadonlyObjects, $this->closureExportPolicy, $this->sourcePathPolicy);
    }

    public function withTrailingComma(bool $trailingComma = true): self
    {
        return new self($this->mode, $this->indent, $this->maxDepth, $this->sortKeys, $trailingComma, $this->closureUseMode, $this->allowGenericReadonlyObjects, $this->closureExportPolicy, $this->sourcePathPolicy);
    }

    public function withClosureUseMode(ClosureUseMode $closureUseMode): self
    {
        return new self($this->mode, $this->indent, $this->maxDepth, $this->sortKeys, $this->trailingComma, $closureUseMode, $this->allowGenericReadonlyObjects, $this->closureExportPolicy, $this->sourcePathPolicy);
    }

    public function withGenericReadonlyObjects(bool $allow = true): self
    {
        return new self($this->mode, $this->indent, $this->maxDepth, $this->sortKeys, $this->trailingComma, $this->closureUseMode, $allow, $this->closureExportPolicy, $this->sourcePathPolicy);
    }

    public function withClosureExportPolicy(ClosureExportPolicy $policy): self
    {
        return new self($this->mode, $this->indent, $this->maxDepth, $this->sortKeys, $this->trailingComma, $this->closureUseMode, $this->allowGenericReadonlyObjects, $policy, $this->sourcePathPolicy);
    }

    public function withSourcePathPolicy(SourcePathPolicy $policy): self
    {
        return new self($this->mode, $this->indent, $this->maxDepth, $this->sortKeys, $this->trailingComma, $this->closureUseMode, $this->allowGenericReadonlyObjects, $this->closureExportPolicy, $policy);
    }

    public static function pretty(): self
    {
        return new self(mode: FormatterMode::Pretty, trailingComma: true);
    }

    public static function compact(): self
    {
        return new self(mode: FormatterMode::Standard);
    }

    public function isPretty(): bool
    {
        return $this->mode === FormatterMode::Pretty;
    }

    /** @throws ConfigurationException */
    private static function assertIndent(string $indent): void
    {
        if ($indent === "\t") {
            return;
        }

        if ($indent !== '' && preg_match('/^ +$/D', $indent) === 1) {
            return;
        }

        throw ConfigurationException::invalidIndent($indent);
    }
}

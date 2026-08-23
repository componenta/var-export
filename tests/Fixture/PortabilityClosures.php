<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture\PortableUnqualified;

use Closure;

function unqualifiedFunctionClosure(): Closure { return static fn(): int => strlen('abc'); }
function unqualifiedConstantClosure(): Closure { return static fn(): int => PHP_INT_MAX; }
function fileClosure(): Closure { return static fn(): string => __FILE__; }
function evalClosure(): Closure { return static fn(): mixed => eval('return 42;'); }

namespace Componenta\VarExport\Tests\Fixture\PortableImported;

use Closure;
use function strlen;

function importedFunctionClosure(): Closure { return static fn(): int => strlen('abc'); }
function qualifiedFunctionClosure(): Closure { return static fn(): int => \strlen('abc'); }

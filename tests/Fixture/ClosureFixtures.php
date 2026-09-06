<?php

declare(strict_types=1);

namespace Componenta\VarExport\Tests\Fixture;

use ArrayObject;
use Closure;
use Countable;
use Generator;
use Iterator;
use LogicException;

use function Componenta\VarExport\Tests\Support\openMemoryStream;
use function strtoupper as uppercase;

use const PHP_INT_MAX as IMPORTED_PHP_INT_MAX;

const SOURCE_LOCAL_CONSTANT = 42;

function simpleClosure(): Closure
{
    return static fn (int $value): int => $value + 1;
}

function blockClosure(): Closure
{
    return static function (int $value): int {
        $next = $value + 1;

        return $next * 2;
    };
}

function multilineLiteralClosure(): Closure
{
    return static fn (): string => 'first
    second
        third';
}

function heredocClosure(): Closure
{
    return static function (): string {
        return <<<SQL
            SELECT id, name
            FROM users
            WHERE active = 1
            SQL;
    };
}

function nowdocClosure(): Closure
{
    return static function (): string {
        return <<<'TEMPLATE'
            {{ greeting }},
                welcome to $site
            TEMPLATE;
    };
}

function capturedClosure(): Closure
{
    $prefix = 'item:';
    $options = ['enabled' => true, 'ports' => [80, 443]];
    $stage = DeploymentStage::Testing;

    return static fn (int $value): array => [$prefix . $value, $options, $stage];
}

function capturedValueClosure(mixed $value): Closure
{
    return static fn (): mixed => $value;
}

function statefulCaptureClosure(): Closure
{
    $counter = 0;

    return static function () use ($counter): int {
        return ++$counter;
    };
}

function assignmentOnlyArrowClosure(): Closure
{
    return static fn (): int => $local = 42;
}

function byReferenceCaptureClosure(): Closure
{
    $value = 1;

    return static function () use (&$value): int {
        return $value;
    };
}

function nestedByReferenceCaptureClosure(): Closure
{
    $value = 1;

    return static fn (): Closure => static function () use (&$value): int {
        return ++$value;
    };
}

function recursiveArrayCaptureClosure(): Closure
{
    $array = [];
    $array['self'] = &$array;

    return static fn (): array => $array;
}

function objectCaptureClosure(): Closure
{
    $object = new \stdClass();

    return static fn (): object => $object;
}

/** @return array{Closure, resource} */
function resourceCaptureClosure(): array
{
    $resource = openMemoryStream();

    return [static fn () => $resource, $resource];
}

function nestedClosureCapture(): Closure
{
    $nested = static fn (): int => 1;

    return static fn (): Closure => $nested;
}

function staticLocalClosure(): Closure
{
    return static function (): int {
        static $counter = 0;
        if (!is_int($counter)) {
            throw new LogicException('The static counter must remain an integer.');
        }

        return ++$counter;
    };
}

function nestedStaticLocalClosure(): Closure
{
    return static fn (): Closure => static function (): int {
        static $counter = 0;

        if (!is_int($counter)) {
            throw new LogicException('The static counter must remain an integer.');
        }

        return ++$counter;
    };
}

function evalClosure(): Closure
{
    return static fn (): mixed => eval('return 42;');
}

function includeClosure(): Closure
{
    return static fn (): mixed => include __FILE__;
}

function requireClosure(): Closure
{
    return static fn (): mixed => require __FILE__;
}

function sourceFileClosure(): Closure
{
    return static fn (): string => __FILE__;
}

function sourceDirectoryClosure(): Closure
{
    return static fn (): string => __DIR__;
}

function sourceLocalDependency(): string
{
    return 'source-local';
}

function sourceEnvironmentClosure(): Closure
{
    return static fn (): string => \Componenta\VarExport\Tests\Fixture\sourceLocalDependency();
}

function sourceLocalConstantClosure(): Closure
{
    return static fn (): int => SOURCE_LOCAL_CONSTANT;
}

function portableFunctionClosure(): Closure
{
    return static fn (string $value): string => strtoupper($value);
}

function portableConstantClosure(): Closure
{
    return static fn (): int => PHP_INT_MAX;
}

function importedFunctionClosure(): Closure
{
    return static fn (string $value): string => uppercase($value);
}

function importedConstantClosure(): Closure
{
    return static fn (): int => IMPORTED_PHP_INT_MAX;
}

function automaticGlobalsClosure(): Closure
{
    return static fn (string $key): array => [
        $GLOBALS[$key] ?? null,
        $_SERVER[$key] ?? null,
        $_GET[$key] ?? null,
        $_POST[$key] ?? null,
        $_FILES[$key] ?? null,
        $_COOKIE[$key] ?? null,
        $_SESSION[$key] ?? null,
        $_REQUEST[$key] ?? null,
        $_ENV[$key] ?? null,
    ];
}

function unionSignatureClosure(): Closure
{
    return static fn (int|string|null $value = null): int|string|null => $value;
}

function classConstantDefaultClosure(): Closure
{
    return static fn (int $value = ClosureSignatureDefaults::VALUE): int => $value;
}

function globalConstantDefaultClosure(): Closure
{
    return static fn (int $value = PHP_INT_MAX): int => $value;
}

function safeLiteralDefaultsClosure(): Closure
{
    return static fn (
        ?string $nullable = null,
        bool $enabled = true,
        int $offset = -7,
        float $signedZero = -0.0,
        string $label = 'componenta',
        array $options = ['ports' => [80, 443], 'enabled' => false],
    ): array => [$nullable, $enabled, $offset, $signedZero, $label, $options];
}

function dnfSignatureClosure(): Closure
{
    return static fn ((Iterator&Countable)|array $value): (Iterator&Countable)|array => $value;
}

function nonStaticUnboundClosure(): Closure
{
    return fn (int $value): int => $value + 1;
}

function nonStaticCapturedClosure(): Closure
{
    $value = 41;

    return fn (): int => $value + 1;
}

function generatorClosure(): Closure
{
    return static function (int $start): Generator {
        yield $start;
        yield $start + 1;
    };
}

function importedClassClosure(): Closure
{
    return static fn (array $values): ArrayObject => new ArrayObject($values);
}

function attributedClosure(): Closure
{
    return #[ClosureMarker('portable')]
    static fn (#[\SensitiveParameter] string $value = 'value'): string => $value;
}

function safeAttributedClosure(): Closure
{
    return #[ClosurePayloadAttribute(value: ['enabled' => true, 'signed_zero' => -0.0])]
    static fn (): int => 42;
}

function referenceSignatureClosure(): Closure
{
    return static function &(int &$value, string ...$suffixes): int {
        $value += count($suffixes);

        return $value;
    };
}

function complexDefaultClosure(): Closure
{
    return static fn (mixed $value = new DefaultConstructionProbe()): mixed => $value;
}

function unverifiableAttributedClosure(): Closure
{
    return #[ClosurePayloadAttribute(new DefaultConstructionProbe())]
    static fn (): int => 42;
}

function nestedGeneratorClosure(): Closure
{
    return static fn (): Closure => static function (): Generator {
        yield from [41, 42];
    };
}

function nestedArrowCaptureClosure(): Closure
{
    $value = 42;

    return static fn (): Closure => static fn (): int => $value;
}

function destructuringArrowClosure(): Closure
{
    return static fn (): array => [$left, $right] = [1, 2];
}

function keyedDestructuringArrowClosure(): Closure
{
    $key = 'answer';

    return static fn (): array => [$key => $value] = ['answer' => 42];
}

function anonymousScopeClosure(): Closure
{
    return (new class () {
        public function make(): Closure
        {
            return static fn (): string => self::class;
        }
    })->make();
}

function closedResourceCaptureClosure(): Closure
{
    $resource = openMemoryStream();
    fclose($resource);

    return static fn () => $resource;
}

function namedCallableClosure(): Closure
{
    return strlen(...);
}

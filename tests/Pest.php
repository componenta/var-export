<?php

declare(strict_types=1);

/*
 * Pest bootstrap for Componenta\VarExport regression tests.
 *
 * Each test file under tests/ that uses Pest's describe()/it()/test() syntax
 * picks up its base class from here.
 *
 * We deliberately stick to \PHPUnit\Framework\TestCase - the library has no
 * application-specific base test class, so Pest tests inherit the same
 * lifecycle as the existing PHPUnit ones.
 */

uses(\PHPUnit\Framework\TestCase::class)
    ->in(__DIR__ . '/Regression');

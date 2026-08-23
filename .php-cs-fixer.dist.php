<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src');

return (new PhpCsFixer\Config())
    ->setRules([
        'blank_line_after_opening_tag' => true,
        'no_trailing_whitespace' => true,
        'single_blank_line_at_eof' => true,
    ])
    ->setFinder($finder);

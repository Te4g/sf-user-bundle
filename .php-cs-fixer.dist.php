<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude([
        '.worktrees',
        'var',
        'vendor',
    ]);

return (new PhpCsFixer\Config())
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@PSR12' => true,
        'ordered_attributes' => true,
        'ordered_class_elements' => true,
        'ordered_imports' => true,
        'ordered_interfaces' => true,
    ])
    ->setFinder($finder);

<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withPhpSets()
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true)
    ->withSkip([
        // Untyped Pest closures (it/test/beforeEach/afterEach) are the house style in
        // tests/; typing every test callback is churn that adds no safety in a test suite.
        // Scoped to tests/ only — src/ keeps the rule for any future untyped closure.
        AddClosureVoidReturnTypeWhereNoReturnRector::class => [__DIR__.'/tests'],
    ]);

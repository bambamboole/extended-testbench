<?php
declare(strict_types=1);

it('resolves the test namespace from autoload-dev without touching composer.json', function () {
    $context = makeContext(['autoload-dev' => ['psr-4' => ['Acme\\Tests\\' => 'tests/']]]);
    $before = file_get_contents($context->path('composer.json'));

    expect($context->testNamespace())->toBe('Acme\\Tests\\')
        ->and(file_get_contents($context->path('composer.json')))->toBe($before);
});

it('falls back to Tests\\ when no autoload-dev entry maps to tests/', function () {
    expect(makeContext()->testNamespace())->toBe('Tests\\');
});

it('reports which feature flags resolved true', function () {
    $context = makeContext(flags: ['pint' => true, 'rector' => false]);

    expect($context->enabled('pint'))->toBeTrue()
        ->and($context->enabled('rector'))->toBeFalse()
        ->and($context->enabled('never-registered'))->toBeFalse();
});

it('renders a stub with its replacements', function () {
    expect(makeContext()->render('artisan.stub', []))
        ->toContain("require __DIR__.'/vendor/bin/testbench';");
});

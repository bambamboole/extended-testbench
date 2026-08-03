<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\AllowedPlugin;
use Bambamboole\ExtendedTestbench\Features\Status;

it('labels itself by the plugin', function () {
    expect(new AllowedPlugin('pestphp/pest-plugin')->label())
        ->toBe('composer allow-plugins: pestphp/pest-plugin');
});

it('reports missing under check when the plugin is not allowed yet', function () {
    $result = first(new AllowedPlugin('pestphp/pest-plugin')->drift(makeContext()));

    expect($result->status)->toBe(Status::Missing);
});

it('allows the plugin on apply', function () {
    $context = makeContext();

    $result = first(new AllowedPlugin('pestphp/pest-plugin')->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and($result->describe())->toBe('allowed')
        ->and($context->composerJson()['config']['allow-plugins']['pestphp/pest-plugin'])->toBeTrue();
});

it('yields nothing on drift or apply once allowed, touching nothing', function () {
    $context = makeContext(['config' => ['allow-plugins' => ['pestphp/pest-plugin' => true]]]);
    $artifact = new AllowedPlugin('pestphp/pest-plugin');
    $before = file_get_contents($context->path('composer.json'));

    expect(iterator_to_array($artifact->drift($context), false))->toBeEmpty()
        ->and(iterator_to_array($artifact->apply($context), false))->toBeEmpty()
        ->and(file_get_contents($context->path('composer.json')))->toBe($before);
});

it('does not treat an explicitly denied plugin as allowed', function () {
    $context = makeContext(['config' => ['allow-plugins' => ['pestphp/pest-plugin' => false]]]);

    expect(first(new AllowedPlugin('pestphp/pest-plugin')->drift($context))->status)
        ->toBe(Status::Missing);
});

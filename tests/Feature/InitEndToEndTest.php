<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * The one test in the suite that runs a REAL composer and the REAL scaffolded toolchain. Every
 * other test doubles requirePackages(), which is exactly how three write-path bugs shipped:
 * a missing allow-plugins entry killing the first install, a failed install that composer.json
 * made unrecoverable, and a scaffolded tests/Pest.php failing the scaffolded pint. Slow and
 * network-bound by design — this is the adopter's first fifteen minutes, executed literally.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/etb-e2e-'.bin2hex(random_bytes(4));

    mkdir($this->root.'/src', 0755, true);

    file_put_contents($this->root.'/src/Greeter.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Integration;

        final readonly class Greeter
        {
            public function greet(string $name): string
            {
                return "Hello {$name}";
            }
        }
        PHP.PHP_EOL);

    file_put_contents($this->root.'/composer.json', json_encode([
        'name' => 'acme/integration',
        // The php constraint is not decoration: the scaffolded rector.php derives its PHP sets
        // from it and errors out when a package never declares one.
        'require' => ['php' => '^8.4'],
        'autoload' => ['psr-4' => ['Acme\\Integration\\' => 'src/']],
        'repositories' => [['type' => 'path', 'url' => dirname(__DIR__, 2), 'options' => ['symlink' => true]]],
        'minimum-stability' => 'dev',
        'prefer-stable' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->root);
});

function runIn(string $root, string ...$command): Process
{
    $process = new Process([...$command], $root, ['COMPOSER_NO_INTERACTION' => '1'], timeout: 600);
    $process->run();

    return $process;
}

test('a fresh package:init produces a package that passes its own composer check', function () {
    $require = runIn($this->root, 'composer', 'require', '--dev', 'bambamboole/extended-testbench:@dev', '--no-interaction');
    expect($require->isSuccessful())->toBeTrue($require->getOutput().$require->getErrorOutput());

    $init = runIn($this->root, PHP_BINARY, 'vendor/bin/testbench', 'package:init', '--defaults', '--no-interaction');
    $initOutput = $init->getOutput().$init->getErrorOutput();

    expect($init->isSuccessful())->toBeTrue($initOutput)
        ->and($initOutput)->not->toContain('Failed to install');

    $composer = json_decode((string) file_get_contents($this->root.'/composer.json'), true);

    expect($composer['config']['allow-plugins']['pestphp/pest-plugin'] ?? null)->toBeTrue();

    foreach (['pest', 'pint', 'phpstan', 'rector'] as $binary) {
        expect($this->root."/vendor/bin/{$binary}")->toBeFile();
    }

    // The scaffolded suites are empty and pest fails on an empty suite, so give the package the
    // first test its adopter would write before running its own quality gate.
    file_put_contents($this->root.'/tests/Unit/GreeterTest.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        use Acme\Integration\Greeter;

        it('greets', function () {
            expect(new Greeter()->greet('world'))->toBe('Hello world');
        });
        PHP.PHP_EOL);

    $check = runIn($this->root, 'composer', 'check');
    expect($check->isSuccessful())->toBeTrue($check->getOutput().$check->getErrorOutput());

    $drift = runIn($this->root, PHP_BINARY, 'vendor/bin/testbench', 'package:init', '--check');
    expect($drift->isSuccessful())->toBeTrue($drift->getOutput().$drift->getErrorOutput());
})->group('integration');

test('a run whose installs failed is retryable and fails --check instead of certifying the wreck', function () {
    $require = runIn($this->root, 'composer', 'require', '--dev', 'bambamboole/extended-testbench:@dev', '--no-interaction');
    expect($require->isSuccessful())->toBeTrue($require->getOutput().$require->getErrorOutput());

    // Reproduce the wreck a failed `composer require` leaves behind: constraints in composer.json
    // with nothing in vendor/.
    $composer = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
    $composer['require-dev']['laravel/pint'] = '^1.16';
    file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $drift = runIn($this->root, PHP_BINARY, 'vendor/bin/testbench', 'package:init', '--check');

    expect($drift->isSuccessful())->toBeFalse('the constraint sits in composer.json with no vendor dir — this must count as drift')
        ->and($drift->getOutput())->toContain('laravel/pint:^1.16');
})->group('integration');

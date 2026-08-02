<?php
declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

it('describes itself with the status label when it carries no detail', function () {
    expect(new Result('pint.json', Status::Ok)->describe())->toBe('ok')
        ->and(new Result('pint.json', Status::Missing)->describe())->toBe('missing')
        ->and(new Result('pint.json', Status::Written)->describe())->toBe('written');
});

it('prefers the detail string so table rows keep their parenthetical', function () {
    $result = new Result('pint.json', Status::Skipped, 'skipped (exists, --force to replace)');

    expect($result->describe())->toBe('skipped (exists, --force to replace)')
        ->and($result->status)->toBe(Status::Skipped);
});

it('knows which statuses count as drift', function () {
    expect(Status::Ok->isDrift())->toBeFalse()
        ->and(Status::NotCheckable->isDrift())->toBeFalse()
        ->and(Status::Written->isDrift())->toBeFalse()
        ->and(Status::Overwritten->isDrift())->toBeFalse()
        ->and(Status::Skipped->isDrift())->toBeFalse()
        ->and(Status::Ran->isDrift())->toBeFalse()
        ->and(Status::Missing->isDrift())->toBeTrue()
        ->and(Status::Differs->isDrift())->toBeTrue()
        ->and(Status::Failed->isDrift())->toBeTrue();
});

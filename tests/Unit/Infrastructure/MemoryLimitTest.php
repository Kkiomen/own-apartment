<?php

declare(strict_types=1);

use App\Infrastructure\Console\ScanPortalsCommand;

it('reads PHP shorthand memory limits', function (string $limit, int $expected): void {
    expect(ScanPortalsCommand::bytes($limit))->toBe($expected);
})->with([
    'the CLI default' => ['128M', 134_217_728],
    'gigabytes' => ['1G', 1_073_741_824],
    'kilobytes' => ['512K', 524_288],
    'plain bytes' => ['268435456', 268_435_456],
    'unlimited' => ['-1', -1],
    'unset' => ['', -1],
    'padded' => [' 256M ', 268_435_456],
]);

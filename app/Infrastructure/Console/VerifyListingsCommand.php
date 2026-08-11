<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Verification\VerifyListings;
use Illuminate\Console\Command;

/**
 * Driving adapter: turns "the scheduler fired" into a use-case call.
 *
 * Contains no business logic — everything it knows how to do is print.
 */
final class VerifyListingsCommand extends Command
{
    protected $signature = 'listings:verify {--limit= : How many adverts to check in this run}';

    protected $description = 'Re-check known adverts against their offer page and retire the ones the portal has withdrawn';

    public function handle(VerifyListings $verify): int
    {
        $limit = $this->option('limit');

        $report = $verify(is_numeric($limit) ? (int) $limit : null);

        if ($report->checked() === 0 && $report->skipped() === 0) {
            $this->info('Nothing due for verification.');

            return self::SUCCESS;
        }

        $this->table(
            ['Checked', 'Still up', 'Withdrawn', 'Unreachable', 'Skipped', 'Flats retired', 'Galleries filled'],
            [[
                $report->checked(),
                $report->available(),
                $report->gone(),
                $report->unreachable(),
                $report->skipped(),
                $report->retiredListings(),
                $report->galleriesFilled(),
            ]],
        );

        if ($report->refusedPortals() !== []) {
            // Not a failure of ours, but it must not read as a clean run either:
            // those adverts were never actually checked.
            $this->warn('Refused by: '.implode(', ', $report->refusedPortals()).' — their adverts stay queued for the next run.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Scanning\PortalScanSummary;
use App\Application\Scanning\ScanPortals;
use App\Domain\Listing\Port\ListingCatalog;
use Illuminate\Console\Command;

/**
 * Driving adapter: turns "the scheduler fired" into a use-case call.
 *
 * Contains no business logic — everything it knows how to do is print.
 */
final class ScanPortalsCommand extends Command
{
    protected $signature = 'listings:scan {--if-empty : Only scan when we have no listings yet}';

    protected $description = 'Scan every enabled portal for flats matching our criteria';

    /**
     * A single OLX search page is ~3.6 MB of HTML holding a JSON blob that
     * decodes to several times that. PHP's 128 MB CLI default is not enough,
     * and the scheduler spawns this command with whatever the CLI default is —
     * so it has to raise the ceiling itself rather than rely on the operator
     * remembering a -d flag. The Docker image already sets 512 MB; this is what
     * makes the command survive outside it.
     */
    private const REQUIRED_MEMORY_BYTES = 512 * 1024 * 1024;

    public function handle(ScanPortals $scan, ListingCatalog $catalog): int
    {
        // Used by the first-run service on a fresh deployment: fill the app so
        // it is not an empty screen for an hour, but do not re-scrape five
        // portals every time the stack is restarted.
        if ($this->option('if-empty') && $catalog->stats()['total'] > 0) {
            $this->info('Listings already present — skipping the initial scan.');

            return self::SUCCESS;
        }

        $this->ensureEnoughMemory();

        $report = $scan();

        if ($report->summaries() === []) {
            $this->warn('No portals are enabled — nothing to scan.');

            return self::SUCCESS;
        }

        $this->table(
            ['Portal', 'Fetched', 'New', 'Price changed', 'Unchanged', 'Rejected', 'Retired', 'Status'],
            array_map(static fn (PortalScanSummary $s): array => [
                $s->portal->label(),
                $s->fetched(),
                $s->created(),
                $s->priceChanged(),
                $s->unchanged(),
                $s->rejected(),
                $s->retired(),
                $s->failed() ? 'FAILED' : 'ok',
            ], $report->summaries()),
        );

        foreach ($report->failures() as $failure) {
            $this->error("{$failure->portal->label()}: {$failure->failure()}");
        }

        $this->info(sprintf(
            '%d new match(es), %d price change(s) out of %d listings seen. %d flat(s) no longer advertised anywhere. %d re-ranked.',
            $report->totalCreated(),
            $report->totalPriceChanged(),
            $report->totalFetched(),
            $report->retiredListings(),
            $report->appraised(),
        ));

        // A failed portal must not look like a healthy run to the scheduler.
        return $report->failures() === [] ? self::SUCCESS : self::FAILURE;
    }

    private function ensureEnoughMemory(): void
    {
        $current = self::bytes((string) ini_get('memory_limit'));

        // -1 means unlimited, which is already fine.
        if ($current !== -1 && $current < self::REQUIRED_MEMORY_BYTES) {
            ini_set('memory_limit', (string) self::REQUIRED_MEMORY_BYTES);
        }
    }

    /**
     * Turns PHP's shorthand notation ("128M", "1G") into bytes.
     */
    public static function bytes(string $limit): int
    {
        $limit = trim($limit);

        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}

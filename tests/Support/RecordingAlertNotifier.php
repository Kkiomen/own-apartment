<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Alert\AlertReason;
use App\Domain\Alert\ListingAlert;
use App\Domain\Alert\Port\AlertNotifier;

final class RecordingAlertNotifier implements AlertNotifier
{
    /** @var list<ListingAlert> */
    public array $sent = [];

    public function send(ListingAlert $alert): void
    {
        $this->sent[] = $alert;
    }

    /**
     * @return list<ListingAlert>
     */
    public function withReason(AlertReason $reason): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn (ListingAlert $alert): bool => $alert->reason === $reason,
        ));
    }

    public function count(): int
    {
        return count($this->sent);
    }
}

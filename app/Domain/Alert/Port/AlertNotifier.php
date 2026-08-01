<?php

declare(strict_types=1);

namespace App\Domain\Alert\Port;

use App\Domain\Alert\ListingAlert;

/**
 * Outbound notification port.
 *
 * No channel is wired up yet by decision — we want to be happy with what the
 * app shows before anything starts buzzing phones. The port exists now so that
 * adding Telegram later is a new adapter plus one container binding, and
 * nothing in the domain or the use cases changes.
 */
interface AlertNotifier
{
    public function send(ListingAlert $alert): void;
}

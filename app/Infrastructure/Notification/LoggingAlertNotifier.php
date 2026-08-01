<?php

declare(strict_types=1);

namespace App\Infrastructure\Notification;

use App\Domain\Alert\ListingAlert;
use App\Domain\Alert\Port\AlertNotifier;
use Psr\Log\LoggerInterface;

/**
 * Placeholder adapter until we pick a real channel.
 *
 * Logs rather than doing nothing on purpose: while we are still judging whether
 * the app finds the right flats, the log is the evidence of what *would* have
 * been pushed to our phones. A silent no-op would make an under- or
 * over-notifying rule impossible to spot.
 *
 * Swapping in Telegram later is one binding in DomainServiceProvider.
 */
final readonly class LoggingAlertNotifier implements AlertNotifier
{
    public function __construct(private LoggerInterface $logger) {}

    public function send(ListingAlert $alert): void
    {
        $listing = $alert->listing;

        $this->logger->info('Listing alert', [
            'reason' => $alert->reason->value,
            'listing_id' => $listing->id(),
            'title' => $listing->title(),
            'city' => $listing->city()->label(),
            'area' => $listing->area()->format(),
            'rooms' => $listing->rooms(),
            'price' => $listing->price()->format(),
            'price_change' => $alert->priceChange === null ? null : [
                'from' => $alert->priceChange->from->format(),
                'to' => $alert->priceChange->to->format(),
                'percent' => $alert->priceChange->percentageChange(),
            ],
            'urls' => array_map(
                static fn ($source): string => $source->url,
                $listing->sources(),
            ),
        ]);
    }
}

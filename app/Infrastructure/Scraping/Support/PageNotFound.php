<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use RuntimeException;

/**
 * The portal answered 404.
 *
 * Distinguished from any other failure because it means two very different
 * things depending on where it happens. Past page one it is simply the end of
 * the results — Gratka and Morizon 404 instead of serving an empty page, so
 * treating it as an error loses every listing gathered from that scan. On page
 * one it is a genuine problem, usually a city slug that no longer resolves.
 */
final class PageNotFound extends RuntimeException {}

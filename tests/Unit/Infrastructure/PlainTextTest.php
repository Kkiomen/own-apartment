<?php

declare(strict_types=1);

use App\Infrastructure\Scraping\Support\PlainText;

it('strips the HTML portals wrap descriptions in', function (): void {
    $html = '<p>Do sprzedania <strong>ładne</strong> mieszkanie.</p><p>Blisko szkoła.</p>';

    expect(PlainText::from($html))->toBe("Do sprzedania ładne mieszkanie.\nBlisko szkoła.");
});

it('decodes entities so descriptions read like Polish', function (): void {
    expect(PlainText::from('Powierzchnia 51&nbsp;m&sup2; &amp; balkon'))
        ->toBe('Powierzchnia 51 m² & balkon');
});

it('turns line breaks into newlines', function (): void {
    expect(PlainText::from('Pierwsze<br>Drugie<br/>Trzecie'))->toBe("Pierwsze\nDrugie\nTrzecie");
});

it('collapses the runs of blank lines agents leave behind', function (): void {
    expect(PlainText::from("Pierwsze\n\n\n\n\nDrugie"))->toBe("Pierwsze\n\nDrugie");
});

it('truncates a wall of text rather than storing all of it', function (): void {
    $result = PlainText::from(str_repeat('a', 5000), maxLength: 100);

    expect(mb_strlen((string) $result))->toBe(101)   // 100 + the ellipsis
        ->and($result)->toEndWith('…');
});

it('returns null when there is nothing left to show', function (?string $input): void {
    expect(PlainText::from($input))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'only markup' => ['<div><span></span></div>'],
    'only whitespace' => ["  \n\n  "],
]);

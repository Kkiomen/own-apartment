<?php

declare(strict_types=1);

use App\Infrastructure\Scraping\Support\EmbeddedJsonExtractor;

beforeEach(function (): void {
    $this->extractor = new EmbeddedJsonExtractor;
});

it('extracts a JSON payload from a JavaScript string literal', function (): void {
    $payload = json_encode(json_encode(['listing' => ['ads' => [['id' => 1]]]]));
    $html = "<script>window.__STATE__= {$payload};</script>";

    expect($this->extractor->extract($html, '__STATE__'))
        ->toBe(['listing' => ['ads' => [['id' => 1]]]]);
});

it('handles escaped quotes inside the payload', function (): void {
    $payload = json_encode(json_encode(['title' => 'Mieszkanie "Pod Lipami", 3 pokoje']));
    $html = "<script>window.__STATE__= {$payload};</script>";

    expect($this->extractor->extract($html, '__STATE__'))
        ->toBe(['title' => 'Mieszkanie "Pod Lipami", 3 pokoje']);
});

it('survives a payload far larger than PCRE would tolerate', function (): void {
    // The reason this class exists instead of a regex: a multi-megabyte page
    // makes preg_match hit its backtrack limit and return false *silently*,
    // which looks exactly like "the portal has no listings today".
    $big = ['ads' => array_map(
        static fn (int $i): array => ['id' => $i, 'title' => str_repeat('a', 200)],
        range(1, 5000),
    )];

    $payload = json_encode(json_encode($big));
    $html = "<script>window.__STATE__= {$payload};</script>";

    $result = $this->extractor->extract($html, '__STATE__');

    expect($result)->not->toBeNull()
        ->and($result['ads'])->toHaveCount(5000);
});

it('returns null instead of guessing when the payload is missing or broken', function (string $html): void {
    expect($this->extractor->extract($html, '__STATE__'))->toBeNull();
})->with([
    'no marker' => ['<html><body>consent wall</body></html>'],
    'marker but no string' => ['<script>window.__STATE__ = undefined;</script>'],
    'unterminated literal' => ['<script>window.__STATE__= "{\"a\":1}</script>'],
    'not json inside' => ['<script>window.__STATE__= "not json at all";</script>'],
]);

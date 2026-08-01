<?php

declare(strict_types=1);

namespace App\Domain\Listing\Appraisal;

/**
 * The verdict on one listing: what stands out about it, and a single number to
 * rank it by.
 */
final readonly class Appraisal
{
    /**
     * @param  list<array{label: string, text: string, tone: string}>  $labels
     */
    private function __construct(
        public int $score,
        public array $labels,
    ) {}

    /**
     * @param  list<array{ListingLabel, string}>  $found  label with its filled-in text
     */
    public static function of(array $found): self
    {
        $score = 0;
        $labels = [];

        foreach ($found as [$label, $text]) {
            $score += $label->weight();
            $labels[] = [
                'label' => $label->value,
                'text' => $text,
                'tone' => $label->tone(),
            ];
        }

        return new self($score, $labels);
    }

    public static function none(): self
    {
        return new self(0, []);
    }
}

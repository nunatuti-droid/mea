<?php
declare(strict_types=1);

namespace Mea\Module\Exams;

/** Ein Ort für die Preisregel: Terminpreis vor Grundpreis, plus Pflichtgebühren und gewählte Zusatzgebühren. */
final class PriceCalculator
{
    /**
     * @param array<string,mixed> $exam
     * @param array<string,mixed>|null $session
     * @param list<array<string,mixed>> $fees
     * @param list<int> $chosenFeeIds
     * @return array{base_cents:int, fees:list<array{label:string,amount_cents:int}>, total_cents:int, currency:string}|null
     */
    public function calculate(array $exam, ?array $session, array $fees = [], array $chosenFeeIds = []): ?array
    {
        $base = $session['price_cents'] ?? null;
        $base ??= $exam['base_price_cents'] ?? null;
        if ($base === null) {
            return null;
        }
        $lines = [];
        $total = (int) $base;
        foreach ($fees as $fee) {
            if ((int) $fee['is_mandatory'] === 1 || in_array((int) $fee['id'], $chosenFeeIds, true)) {
                $lines[] = ['label' => (string) $fee['label'], 'amount_cents' => (int) $fee['amount_cents']];
                $total += (int) $fee['amount_cents'];
            }
        }
        return [
            'base_cents' => (int) $base,
            'fees' => $lines,
            'total_cents' => $total,
            'currency' => (string) ($exam['currency'] ?? 'EUR'),
        ];
    }
}

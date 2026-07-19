<?php

use App\Domain\Billing\OpenPlayBillingCalculator;

test('billing amount is calculated per table', function (int $elapsedSeconds, int $hourlyRate, int $incrementMinutes, int $expected) {
    expect(OpenPlayBillingCalculator::calculate($elapsedSeconds, $hourlyRate, $incrementMinutes))
        ->toBe($expected);
})->with([
    'zero minutes played costs nothing' => [0, 5000, 1, 0],
    'negative elapsed (clock skew) is treated as zero' => [-5, 5000, 1, 0],
    'exactly on the 1-minute increment boundary' => [5 * 60, 5000, 1, 417],
    'one second past the 1-minute boundary rounds up a full minute' => [5 * 60 + 1, 5000, 1, 500],
    'exactly one hour bills the full hourly rate' => [3600, 5000, 1, 5000],
    'rate evenly divisible by 60 needs no ceiling artifact' => [5 * 60, 6000, 1, 500],
    'one second past an even-rate boundary still rounds up' => [5 * 60 + 1, 6000, 1, 600],
    'a 15-minute increment rounds a 16-minute session up to 30' => [16 * 60, 6000, 15, 3000],
    'a 15-minute increment bills nothing extra exactly on the boundary' => [15 * 60, 6000, 15, 1500],
]);

test('calculate never returns a float-producing value outside int range', function () {
    $result = OpenPlayBillingCalculator::calculate(3661, 5000, 1);

    expect($result)->toBeInt();
});

// Pembulatan 0 menit dulu melempar DivisionByZeroError tepat saat kasir
// menutup sesi, sehingga sesinya tidak bisa diselesaikan sama sekali.
test('an increment of zero is treated as one minute instead of crashing', function () {
    expect(OpenPlayBillingCalculator::calculate(3600, 5000, 0))
        ->toBe(OpenPlayBillingCalculator::calculate(3600, 5000, 1));
});

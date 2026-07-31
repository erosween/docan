<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

class SalesReportWorkbook
{
    /**
     * Build a standards-compliant Excel workbook with three summary sheets.
     */
    public function build(Collection $transactions, Collection $expenses, CarbonImmutable $from, CarbonImmutable $to, string $outletName): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docan-report-');
        if ($path === false) {
            throw new RuntimeException('Tidak dapat membuat file laporan sementara.');
        }

        $writer = new Writer;
        $writer->setCreator('Docan');
        $writer->openToFile($path);

        foreach (['Daily' => 'daily', 'Weekly' => 'weekly', 'Monthly' => 'monthly'] as $sheetName => $period) {
            $sheet = $sheetName === 'Daily'
                ? $writer->getCurrentSheet()
                : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($sheetName);
            $sheet->setColumnWidth(24, 1);
            $sheet->setColumnWidth(24, 2);
            $sheet->setColumnWidth(38, 3);
            $sheet->setColumnWidth(15, 4, 5);
            $sheet->setColumnWidth(20, 6, 7, 8, 9, 10);
            $this->writeSheet(
                $writer,
                $this->aggregate($transactions, $expenses, $period),
                $outletName,
                $from,
                $to
            );
        }

        $writer->close();

        return $path;
    }

    private function writeSheet(
        Writer $writer,
        Collection $rows,
        string $outletName,
        CarbonImmutable $from,
        CarbonImmutable $to
    ): void {
        $titleStyle = (new Style)
            ->setFontBold()
            ->setFontSize(18)
            ->setFontColor(Color::rgb(41, 36, 56));
        $subtitleStyle = (new Style)->setFontColor(Color::rgb(117, 111, 123));
        $headerStyle = (new Style)
            ->setFontBold()
            ->setFontColor(Color::WHITE)
            ->setBackgroundColor(Color::rgb(43, 38, 56))
            ->setCellAlignment(CellAlignment::CENTER);
        $moneyStyle = (new Style)->setFormat('"Rp "#,##0;[Red]-"Rp "#,##0;"-"');
        $totalStyle = (new Style)
            ->setFontBold()
            ->setBackgroundColor(Color::rgb(255, 239, 196));
        $totalMoneyStyle = (clone $totalStyle)->setFormat('"Rp "#,##0;[Red]-"Rp "#,##0;"-"');

        $writer->addRow(Row::fromValues(['Laporan Penjualan Docan'], $titleStyle));
        $writer->addRow(Row::fromValues(["Outlet: {$outletName}"], $subtitleStyle));
        $writer->addRow(Row::fromValues(['Rentang: '.$from->format('Y-m-d H:i').' — '.$to->format('Y-m-d H:i')], $subtitleStyle));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            'Periode',
            'Group Produk',
            'Produk / Denom',
            'Transaksi',
            'Item Terjual',
            'Omset',
            'Modal Produk',
            'Laba Kotor',
            'Biaya Operasional',
            'Laba Bersih',
        ], $headerStyle));

        foreach ($rows as $values) {
            $writer->addRow(Row::fromValuesWithStyles($values, null, [
                5 => $moneyStyle,
                6 => $moneyStyle,
                7 => $moneyStyle,
                8 => $moneyStyle,
                9 => $moneyStyle,
            ]));
        }

        $totals = [
            'TOTAL',
            '',
            '',
            (int) $rows->sum(3),
            (int) $rows->sum(4),
            (int) $rows->sum(5),
            (int) $rows->sum(6),
            (int) $rows->sum(7),
            (int) $rows->sum(8),
            (int) $rows->sum(9),
        ];
        $writer->addRow(Row::fromValuesWithStyles($totals, $totalStyle, [
            5 => $totalMoneyStyle,
            6 => $totalMoneyStyle,
            7 => $totalMoneyStyle,
            8 => $totalMoneyStyle,
            9 => $totalMoneyStyle,
        ]));
    }

    private function aggregate(Collection $transactions, Collection $expenses, string $period): Collection
    {
        $sales = $transactions->groupBy(function ($transaction) use ($period) {
            return implode("\x1F", [
                $this->periodKey(CarbonImmutable::parse($transaction->created_at), $period),
                $this->productGroup($transaction),
                $this->productLabel($transaction),
            ]);
        });
        $costs = $expenses->groupBy(function ($expense) use ($period) {
            return implode("\x1F", [
                $this->periodKey(CarbonImmutable::parse($expense->entry_date), $period),
                'Biaya Operasional',
                ($expense->category ?? null)?->name ?? $expense->description ?? 'Biaya lainnya',
            ]);
        });

        return $sales->keys()
            ->merge($costs->keys())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $key) use ($sales, $costs) {
                $rows = $sales->get($key, collect());
                $operationalCost = (int) $costs->get($key, collect())->sum('amount');
                $grossProfit = (int) $rows->sum('profit');
                [$periodLabel, $group, $product] = explode("\x1F", $key);

                return [
                    $periodLabel,
                    $group,
                    $product,
                    $rows->count(),
                    (int) $rows->sum('quantity'),
                    (int) $rows->sum('price'),
                    (int) $rows->sum('cost_price'),
                    $grossProfit,
                    $operationalCost,
                    $grossProfit - $operationalCost,
                ];
            });
    }

    private function productGroup(object $transaction): string
    {
        $provider = strtoupper((string) ($transaction->provider ?? ''));
        $type = strtolower((string) ($transaction->product_type ?? ($transaction->product ?? null)?->category ?? ''));

        if ($provider === 'AKSESORIS' || str_contains($type, 'aksesoris')) {
            return 'Aksesoris HP';
        }
        if (in_array($provider, ['MANDIRI', 'BRI', 'BNI', 'BTN', 'SEABANK', 'BANK_JAGO', 'ICBC', 'CCB', 'BANK_OF_CHINA'], true)) {
            return 'Perbankan';
        }
        if (in_array($provider, ['DANA', 'OVO', 'GOPAY', 'SHOPEEPAY', 'MAXIM', 'BRILINK', 'LINKAJA'], true)) {
            return 'E-Wallet';
        }
        if ($transaction->product_id ?? null) {
            return 'Produk Provider';
        }

        return 'Pulsa & Paket Tembak';
    }

    private function productLabel(object $transaction): string
    {
        $product = $transaction->product ?? null;
        if ($product?->name) {
            return $transaction->provider.' · '.$product->name;
        }

        $type = trim((string) ($transaction->product_type ?? 'Transaksi'));
        $nominal = (int) ($transaction->nominal ?? 0);

        return trim((string) ($transaction->provider ?? '').' · '.$type.($nominal > 0 ? ' · Rp '.number_format($nominal, 0, ',', '.') : ''));
    }

    private function periodKey(CarbonImmutable $date, string $period): string
    {
        return match ($period) {
            'weekly' => $date->startOfWeek()->format('Y-m-d').' s.d. '.$date->endOfWeek()->format('Y-m-d'),
            'monthly' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }
}

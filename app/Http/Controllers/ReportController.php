<?php

namespace App\Http\Controllers;

use App\Models\BusinessEntry;
use App\Models\Product;
use App\Models\ProductStockMovement;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SalesReportWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportController extends Controller
{
    private const PHYSICAL_PROVIDERS = ['TELKOMSEL', 'BYU', 'INDOSAT', 'XL', 'TRI', 'SMARTFREN', 'AXIS'];

    private const RECHARGE_CHANNELS = ['DIGIPOS', 'SIDIVA', 'ISIMPEL', 'RITA', 'MULTI'];

    private const E_WALLETS = ['DANA', 'OVO', 'GOPAY', 'SHOPEEPAY', 'MAXIM', 'BRILINK', 'LINKAJA'];

    private const BANKS = ['MANDIRI', 'BRI', 'BNI', 'BTN', 'SEABANK', 'BANK_JAGO', 'ICBC', 'CCB', 'BANK_OF_CHINA'];

    private const LOGOS = [
        'TELKOMSEL' => 'telkomsel.svg', 'BYU' => 'byu.svg', 'INDOSAT' => 'indosat.svg', 'XL' => 'xl.svg',
        'TRI' => 'tri.svg', 'SMARTFREN' => 'smartfren-official.svg', 'AXIS' => 'axis.svg',
        'DIGIPOS' => 'telkomsel.svg', 'SIDIVA' => 'xl.svg', 'ISIMPEL' => 'indosat.svg', 'RITA' => 'tri.svg', 'MULTI' => 'multi.svg',
        'DANA' => 'dana.webp', 'OVO' => 'ovo.webp', 'GOPAY' => 'gopay.webp', 'SHOPEEPAY' => 'shopeepay.webp',
        'MAXIM' => 'maxim.svg', 'BRILINK' => 'brilink.svg', 'LINKAJA' => 'linkaja.webp',
        'MANDIRI' => 'mandiri.svg', 'BRI' => 'bri.svg', 'BNI' => 'bni.svg', 'BTN' => 'btn.svg',
        'SEABANK' => 'seabank.svg', 'BANK_JAGO' => 'bank-jago.svg',
        'ICBC' => 'icbc.svg', 'CCB' => 'ccb.svg', 'BANK_OF_CHINA' => 'bank-of-china.svg',
    ];

    public function index(Request $request)
    {
        abort_if($request->user()->role === 'super_admin', 403);
        $outletId = $request->user()->outlet_id;
        $monthInput = $request->string('month')->toString();
        try {
            $period = $monthInput !== '' ? CarbonImmutable::createFromFormat('!Y-m', $monthInput) : CarbonImmutable::now()->startOfMonth();
        } catch (\Throwable) {
            $period = CarbonImmutable::now()->startOfMonth();
        }
        $start = $period->startOfMonth();
        $end = $period->endOfMonth();
        $periodKey = $period->format('Y-m');
        $base = Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outletId))
            ->whereBetween('created_at', [$start, $end]);

        $summary = Cache::remember("reports:outlet:{$outletId}:{$periodKey}:summary", 20, function () use ($base, $outletId, $start, $end) {
            $month = (clone $base)->selectRaw('COUNT(*) as count, COALESCE(SUM(price),0) as turnover, COALESCE(SUM(profit),0) as profit')->first();
            $capital = (int) BusinessEntry::where('outlet_id', $outletId)->where('type', 'capital')->whereBetween('entry_date', [$start, $end])->sum('amount');
            $cashInOther = (int) BusinessEntry::where('outlet_id', $outletId)->where('type', 'cash-in')->whereBetween('entry_date', [$start, $end])->sum('amount');
            $operationalExpenses = (int) BusinessEntry::where('outlet_id', $outletId)->where('type', 'operational-expense')->whereBetween('entry_date', [$start, $end])->sum('amount');
            $cashOut = (int) BusinessEntry::where('outlet_id', $outletId)->whereIn('type', ['cash-out', 'purchase', 'operational-expense'])->whereBetween('entry_date', [$start, $end])->sum('amount');
            // Saldo provider disimpan pada kolom stock sebagai nominal rupiah,
            // sehingga tidak boleh ikut dihitung sebagai jumlah barang fisik.
            $stock = Product::where('outlet_id', $outletId)
                ->where('category', '!=', 'Saldo Provider')
                ->selectRaw('COALESCE(SUM(stock),0) as units, COALESCE(SUM(stock * cost_price),0) as value')
                ->first();

            return [
                'count' => (int) $month->count,
                'turnover' => (int) $month->turnover,
                'profit' => (int) $month->profit,
                'stock' => (int) $stock->units,
                'stockValue' => (int) $stock->value,
                'capital' => $capital,
                'productCapital' => (int) $stock->value,
                'operationalCapital' => $capital,
                'operationalExpenses' => $operationalExpenses,
                'salesCashIn' => (int) $month->turnover,
                'otherCashIn' => $cashInOther,
                'cashOut' => $cashOut,
                'netCash' => $capital + (int) $month->turnover + $cashInOther - $cashOut,
            ];
        });

        $weekly = collect([[1, 7], [8, 14], [15, 21], [22, $end->day]])->map(function ($range, $index) use ($base, $period) {
            $from = $period->day($range[0])->startOfDay();
            $to = $period->day($range[1])->endOfDay();
            $row = (clone $base)->whereBetween('created_at', [$from, $to])
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(price),0) as turnover')->first();

            return ['label' => 'M'.($index + 1), 'range' => $range[0].'–'.$range[1], 'omset' => (int) $row->turnover, 'count' => (int) $row->count];
        });

        $topProducts = (clone $base)->whereNotNull('product_id')->with('product')
            ->selectRaw('product_id, COALESCE(SUM(quantity),0) as sold, COUNT(*) as transaction_count, SUM(price) as revenue')
            ->groupBy('product_id')->orderByDesc('sold')->limit(5)->get();

        [$salesFrom, $salesTo] = $this->reportRange($request, 'sales', $start, $end);
        [$salesStartedAt, $salesEndedAt, $salesStartTime, $salesEndTime, $salesTimeEnabled] = $this->salesTimeRange($request, $salesFrom, $salesTo);
        $sales = Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outletId))
            ->whereBetween('created_at', [$salesStartedAt, $salesEndedAt])
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(quantity),0) as item_count, COALESCE(SUM(price),0) as turnover, COALESCE(SUM(profit),0) as profit')
            ->first();
        $salesMargin = $sales->turnover > 0 ? max(0, min(100, (int) round($sales->profit / $sales->turnover * 100))) : 0;
        $selectedOperationalExpenses = (int) BusinessEntry::where('outlet_id', $outletId)
            ->where('type', 'operational-expense')
            ->whereBetween('entry_date', [$salesFrom, $salesTo])
            ->sum('amount');
        $operationalCapitalAsOf = (int) BusinessEntry::where('outlet_id', $outletId)
            ->where('type', 'capital')
            ->whereDate('entry_date', '<=', $salesTo)
            ->sum('amount');
        $operationalCapitalUsed = (int) BusinessEntry::where('outlet_id', $outletId)
            ->whereIn('type', ['purchase', 'operational-expense'])
            ->whereDate('entry_date', '<=', $salesTo)
            ->sum('amount');
        $selectedOperationalCapital = $operationalCapitalAsOf - $operationalCapitalUsed;
        $productCapitalFunded = (int) BusinessEntry::where('outlet_id', $outletId)
            ->where('type', 'purchase')
            ->whereDate('entry_date', '<=', $salesTo)
            ->sum('amount');
        $productCapitalUsed = (int) Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outletId))
            ->where('created_at', '<=', $salesEndedAt)
            ->sum('cost_price');
        $selectedProductCapital = $productCapitalFunded - $productCapitalUsed;

        [$activityFrom, $activityTo] = $this->reportRange($request, 'activity');
        $activityTransactions = Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outletId))
            ->whereBetween('created_at', [$activityFrom->startOfDay(), $activityTo->endOfDay()])
            ->with(['product', 'stockMovements.product'])->get()
            ->map(fn ($record) => ['kind' => 'transaction', 'groups' => ['sale'], 'at' => $record->created_at, 'record' => $record]);
        $activityStock = ProductStockMovement::where('outlet_id', $outletId)
            ->where(function ($query) {
                $query->whereNull('transaction_id')->orWhereIn('type', ['adjust', 'refund']);
            })
            ->whereBetween('created_at', [$activityFrom->startOfDay(), $activityTo->endOfDay()])
            ->with(['product', 'user:id,name'])->get()
            ->map(fn ($record) => [
                'kind' => 'stock',
                'groups' => array_values(array_filter([
                    $record->quantity >= 0 ? 'stock-in' : 'stock-out',
                    $record->type === 'refund' ? 'refund' : null,
                ])),
                'at' => $record->created_at,
                'record' => $record,
            ]);
        $activities = $activityTransactions->concat($activityStock)->sortByDesc('at')->values();
        $activityCounts = [
            'all' => $activities->count(),
            'sale' => $activities->filter(fn ($activity) => in_array('sale', $activity['groups'], true))->count(),
            'stock-in' => $activities->filter(fn ($activity) => in_array('stock-in', $activity['groups'], true))->count(),
            'stock-out' => $activities->filter(fn ($activity) => in_array('stock-out', $activity['groups'], true))->count(),
            'refund' => $activities->filter(fn ($activity) => in_array('refund', $activity['groups'], true))->count(),
        ];

        return view('reports.index', [
            ...$summary,
            'monthCount' => $summary['count'], 'monthTurnover' => $summary['turnover'], 'monthProfit' => $summary['profit'],
            'period' => $period, 'periodKey' => $periodKey, 'weeks' => $weekly, 'topProducts' => $topProducts,
            'salesSummary' => [
                'transactions' => (int) $sales->transaction_count,
                'items' => (int) $sales->item_count,
                'turnover' => (int) $sales->turnover,
                'profit' => (int) $sales->profit,
            ],
            'salesMargin' => $salesMargin, 'salesFrom' => $salesFrom, 'salesTo' => $salesTo,
            'salesStartTime' => $salesStartTime, 'salesEndTime' => $salesEndTime, 'salesTimeEnabled' => $salesTimeEnabled,
            'selectedOperationalExpenses' => $selectedOperationalExpenses,
            'selectedOperationalCapital' => $selectedOperationalCapital, 'productCapital' => $selectedProductCapital,
            'activities' => $activities, 'activityCounts' => $activityCounts,
            'activityFrom' => $activityFrom, 'activityTo' => $activityTo,
        ]);
    }

    public function exportSales(Request $request, SalesReportWorkbook $workbook)
    {
        abort_if($request->user()->role === 'super_admin', 403);
        [$from, $to] = $this->reportRange(
            $request,
            'sales',
            CarbonImmutable::now()->startOfMonth(),
            CarbonImmutable::now()->endOfMonth()
        );
        [$startedAt, $endedAt] = $this->salesTimeRange($request, $from, $to);
        $transactions = Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $request->user()->outlet_id))
            ->whereBetween('created_at', [$startedAt, $endedAt])
            ->orderBy('created_at')
            ->with('product:id,name,category,operator')
            ->get(['id', 'product_id', 'created_at', 'provider', 'product_type', 'nominal', 'quantity', 'price', 'cost_price', 'profit']);
        $expenses = BusinessEntry::with('category:id,name')
            ->where('outlet_id', $request->user()->outlet_id)
            ->where('type', 'operational-expense')
            ->whereBetween('entry_date', [$from->startOfDay(), $to->endOfDay()])
            ->get(['entry_date', 'amount', 'category_id', 'description']);
        $path = $workbook->build(
            $transactions,
            $expenses,
            $startedAt,
            $endedAt,
            $request->user()->outlet?->name ?? 'Outlet'
        );

        $filename = 'laporan-penjualan-docan-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($path) {
            $stream = fopen($path, 'rb');
            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }
            @unlink($path);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function reportRange(
        Request $request,
        string $prefix,
        ?CarbonImmutable $defaultFrom = null,
        ?CarbonImmutable $defaultTo = null
    ): array {
        $today = CarbonImmutable::today();
        $legacyDate = $prefix === 'activity' ? $request->string('date')->toString() : '';
        $fromInput = $request->string("{$prefix}_from")->toString() ?: $legacyDate;
        $toInput = $request->string("{$prefix}_to")->toString() ?: $legacyDate;

        try {
            $from = $fromInput !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $fromInput) : ($defaultFrom ?? $today);
            $to = $toInput !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $toInput) : ($defaultTo ?? $from);
        } catch (\Throwable) {
            return [$today, $today];
        }

        $from = $from->greaterThan($today) ? $today : $from;
        $to = $to->greaterThan($today) ? $today : $to;
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 365) {
            $from = $to->subDays(365);
        }

        return [$from, $to];
    }

    private function salesTimeRange(Request $request, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $enabled = $from->isSameDay($to);
        $validTime = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
        $startTime = $enabled && preg_match($validTime, $request->string('sales_start_time')->toString())
            ? $request->string('sales_start_time')->toString()
            : '00:00';
        $endTime = $enabled && preg_match($validTime, $request->string('sales_end_time')->toString())
            ? $request->string('sales_end_time')->toString()
            : '23:59';

        if ($startTime > $endTime) {
            [$startTime, $endTime] = [$endTime, $startTime];
        }
        $startedAt = $enabled
            ? $from->setTimeFromTimeString($startTime)->startOfMinute()
            : $from->startOfDay();
        $endedAt = $enabled
            ? $to->setTimeFromTimeString($endTime)->endOfMinute()
            : $to->endOfDay();

        return [$startedAt, $endedAt, $startTime, $endTime, $enabled];
    }

    public function updateStockMovement(Request $request, ProductStockMovement $movement)
    {
        abort_unless($request->user()->isOwner(), 403);
        abort_unless($movement->outlet_id === $request->user()->outlet_id
            && ! $movement->transaction_id
            && in_array($movement->type, ['initial', 'increase', 'decrease'], true), 404);
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:1000000000000']]);

        DB::transaction(function () use ($request, $movement, $data) {
            $lockedMovement = ProductStockMovement::lockForUpdate()->findOrFail($movement->id);
            $product = Product::where('outlet_id', $request->user()->outlet_id)
                ->lockForUpdate()->find($lockedMovement->product_id);
            if (! $product) {
                throw ValidationException::withMessages(['quantity' => 'Produk aktivitas ini sudah tidak tersedia.']);
            }
            $sign = $lockedMovement->quantity < 0 ? -1 : 1;
            $newQuantity = $sign * (int) $data['quantity'];
            $delta = $newQuantity - (int) $lockedMovement->quantity;
            if ((int) $product->stock + $delta < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stok tidak mencukupi untuk perubahan aktivitas ini.']);
            }
            if ($delta < 0) {
                $laterRows = ProductStockMovement::where('product_id', $product->id)
                    ->where('id', '>', $lockedMovement->id);
                $laterMinimum = min(
                    (int) (clone $laterRows)->min('stock_before'),
                    (int) (clone $laterRows)->min('stock_after')
                );
                if ($laterRows->exists() && $laterMinimum + $delta < 0) {
                    throw ValidationException::withMessages(['quantity' => 'Perubahan ini membuat riwayat stok berikutnya menjadi negatif.']);
                }
            }

            $product->update(['stock' => (int) $product->stock + $delta]);
            $lockedMovement->update([
                'quantity' => $newQuantity,
                'stock_after' => (int) $lockedMovement->stock_before + $newQuantity,
                'note' => 'Aktivitas stok diedit dari menu Laporan',
            ]);
            ProductStockMovement::where('product_id', $product->id)
                ->where('id', '>', $lockedMovement->id)
                ->increment('stock_before', $delta);
            ProductStockMovement::where('product_id', $product->id)
                ->where('id', '>', $lockedMovement->id)
                ->increment('stock_after', $delta);
        });

        Cache::forget('reports:outlet:'.$request->user()->outlet_id.':'.$movement->created_at->format('Y-m').':summary');

        return back()->with('success', 'Jumlah aktivitas stok berhasil diperbarui.');
    }

    public function detail(Request $request, string $metric)
    {
        abort_if($request->user()->role === 'super_admin', 403);
        abort_unless(in_array($metric, ['turnover', 'profit', 'stock', 'stock-value'], true), 404);

        $outletId = $request->user()->outlet_id;
        try {
            $period = $request->filled('month')
                ? CarbonImmutable::createFromFormat('!Y-m', $request->string('month')->toString())
                : CarbonImmutable::now()->startOfMonth();
        } catch (\Throwable) {
            $period = CarbonImmutable::now()->startOfMonth();
        }
        $periodKey = $period->format('Y-m');
        [$salesFrom, $salesTo] = $this->reportRange($request, 'sales', $period->startOfMonth(), $period->endOfMonth());
        [$salesStartedAt, $salesEndedAt, $salesStartTime, $salesEndTime] = $this->salesTimeRange($request, $salesFrom, $salesTo);
        $group = $request->string('group')->toString();
        if (! in_array($group, ['provider', 'recharge', 'wallet', 'bank', 'accessory', 'phone'], true)) {
            $group = '';
        }

        $meta = [
            'turnover' => ['title' => 'Rincian Omset', 'short' => 'Omset', 'description' => 'Nilai penjualan pada periode terpilih', 'money' => true, 'periodic' => true],
            'profit' => ['title' => 'Rincian Laba', 'short' => 'Laba', 'description' => 'Keuntungan penjualan setelah dikurangi modal', 'money' => true, 'periodic' => true],
            'stock' => ['title' => 'Rincian Total Stok', 'short' => 'Stok', 'description' => 'Posisi stok yang tersedia saat ini', 'money' => false, 'periodic' => false],
            'stock-value' => ['title' => 'Rincian Nilai Modal Stok', 'short' => 'Modal stok', 'description' => 'Nilai stok berdasarkan harga modal saat ini', 'money' => true, 'periodic' => false],
        ][$metric];

        $transactionRows = Transaction::query()
            ->whereHas('user', fn ($query) => $query->where('outlet_id', $outletId))
            ->whereBetween('created_at', [$salesStartedAt, $salesEndedAt])
            ->select([DB::raw('UPPER(provider) as provider_key'), DB::raw("COALESCE(product_type, '') as type_key"), DB::raw("COALESCE(transaction_action, '') as action_key")])
            ->selectRaw('COALESCE(SUM(price),0) as turnover, COALESCE(SUM(profit),0) as profit, COALESCE(SUM(quantity),0) as units')
            ->groupByRaw("UPPER(provider), COALESCE(product_type, ''), COALESCE(transaction_action, '')")
            ->get();
        $productRows = Product::query()->where('outlet_id', $outletId)
            ->select([DB::raw('UPPER(operator) as provider_key'), DB::raw("COALESCE(category, '') as type_key")])
            ->selectRaw('COALESCE(SUM(stock),0) as stock, COALESCE(SUM(stock * cost_price),0) as stock_value')
            ->groupByRaw("UPPER(operator), COALESCE(category, '')")->get();

        $valueOf = fn ($rows) => (int) $rows->sum(match ($metric) {
            'turnover' => 'turnover','profit' => 'profit','stock' => 'stock',default => 'stock_value',
        });
        $source = in_array($metric, ['turnover', 'profit'], true) ? $transactionRows : $productRows;
        $cardsByGroup = [
            'provider' => $this->physicalMetricCards($source, $valueOf, $meta['short']),
            'recharge' => $this->balanceMetricCards($source, $valueOf, self::RECHARGE_CHANNELS, 'recharge', $meta['short']),
            'wallet' => $this->balanceMetricCards($source, $valueOf, self::E_WALLETS, 'wallet', $meta['short']),
            'bank' => $this->balanceMetricCards($source, $valueOf, self::BANKS, 'bank', $meta['short']),
            'accessory' => $this->accessoryMetricCards($source, $valueOf, $meta['short']),
            'phone' => $this->phoneMetricCards($source, $valueOf, $meta['short']),
        ];
        $groupMeta = [
            'provider' => ['title' => 'Produk Provider', 'description' => 'Voucher fisik dan kartu paket', 'icon' => '▤'],
            'recharge' => ['title' => 'Pulsa & Paket Tembak', 'description' => 'Saldo channel, pulsa, PPOB dan digital', 'icon' => 'ϟ'],
            'wallet' => ['title' => 'E-Wallet', 'description' => 'Top up dan layanan keuangan', 'icon' => '▣'],
            'bank' => ['title' => 'Perbankan', 'description' => 'Transfer dan layanan rekening', 'icon' => '▦'],
            'accessory' => ['title' => 'Aksesoris', 'description' => 'Kabel, charger, casing dan lainnya', 'icon' => '⌁'],
            'phone' => ['title' => 'Handphone', 'description' => 'Perangkat handphone berdasarkan merek dan model', 'icon' => '▯'],
        ];
        foreach ($groupMeta as $key => &$item) {
            $item['value'] = $key === 'provider'
                ? (int) ($cardsByGroup[$key][0]['value'] ?? 0)
                : (int) collect($cardsByGroup[$key])->sum('value');
        }
        unset($item);

        return view('reports.detail', compact(
            'metric',
            'meta',
            'period',
            'periodKey',
            'group',
            'groupMeta',
            'cardsByGroup',
            'salesFrom',
            'salesTo',
            'salesStartTime',
            'salesEndTime'
        ));
    }

    private function physicalMetricCards($rows, callable $valueOf, string $label): array
    {
        $cards = collect(self::PHYSICAL_PROVIDERS)->map(function (string $provider) use ($rows, $valueOf, $label) {
            $providerRows = $rows->where('provider_key', $provider);
            $sa = $providerRows->filter(fn ($row) => str_contains(strtolower((string) $row->type_key), 'kartu'));
            $pv = $providerRows->reject(fn ($row) => str_contains(strtolower((string) $row->type_key), 'kartu'));

            return ['key' => $provider, 'title' => $provider === 'BYU' ? 'by.U' : ucfirst(strtolower($provider)),
                'logo' => self::LOGOS[$provider], 'value' => $valueOf($providerRows), 'lines' => [
                    ['label' => $label.' Voucher Fisik', 'value' => $valueOf($pv)], ['label' => $label.' Kartu Paket', 'value' => $valueOf($sa)],
                ]];
        })->all();
        array_unshift($cards, ['key' => 'ALL', 'title' => 'Semua Provider', 'logo' => null,
            'value' => $valueOf($rows->whereIn('provider_key', self::PHYSICAL_PROVIDERS)), 'lines' => [
                ['label' => $label.' Voucher Fisik', 'value' => collect($cards)->sum(fn ($card) => $card['lines'][0]['value'])],
                ['label' => $label.' Kartu Paket', 'value' => collect($cards)->sum(fn ($card) => $card['lines'][1]['value'])],
            ]]);

        return $cards;
    }

    private function balanceMetricCards($rows, callable $valueOf, array $providers, string $group, string $label): array
    {
        $actionLabels = ['receive_payment' => 'Terima pembayaran', 'customer_topup' => 'Top up pelanggan', 'cash_withdrawal' => 'Tarik tunai', 'bill_payment' => 'Bayar tagihan'];

        return collect($providers)->map(function (string $provider) use ($rows, $valueOf, $group, $label, $actionLabels) {
            $providerRows = $rows->where('provider_key', $provider);
            $hasActions = in_array($group, ['wallet', 'bank'], true) && $providerRows->contains(fn ($row) => isset($row->action_key) && $row->action_key !== '');
            $lines = $hasActions
                ? collect($actionLabels)->map(fn ($name, $action) => ['label' => $name, 'value' => $valueOf($providerRows->where('action_key', $action))])->values()->all()
                : [['label' => $label.' tersedia', 'value' => $valueOf($providerRows)]];

            return ['key' => $provider, 'title' => $this->displayReportName($provider), 'logo' => self::LOGOS[$provider] ?? null,
                'value' => $valueOf($providerRows), 'lines' => $lines];
        })->all();
    }

    private function accessoryMetricCards($rows, callable $valueOf, string $label): array
    {
        $items = $rows->filter(fn ($row) => $row->provider_key === 'AKSESORIS'
            || str_contains(strtolower((string) $row->type_key), 'aksesoris'));

        return [['key' => 'AKSESORIS', 'title' => 'Semua Aksesoris', 'logo' => null, 'value' => $valueOf($items),
            'lines' => [['label' => $label.' aksesoris', 'value' => $valueOf($items)]]]];
    }

    private function phoneMetricCards($rows, callable $valueOf, string $label): array
    {
        $items = $rows->filter(fn ($row) => $row->provider_key === 'HANDPHONE'
            || str_contains(strtolower((string) $row->type_key), 'handphone'));

        return [['key' => 'HANDPHONE', 'title' => 'Semua Handphone', 'logo' => 'handphone.svg', 'value' => $valueOf($items),
            'lines' => [['label' => $label.' handphone', 'value' => $valueOf($items)]]]];
    }

    private function displayReportName(string $provider): string
    {
        return match ($provider) {
            'DIGIPOS' => 'DigiPOS','ISIMPEL' => 'iSimpel','GOPAY' => 'GoPay','SHOPEEPAY' => 'ShopeePay',
            'BRILINK' => 'BRILink', 'LINKAJA' => 'LinkAja', 'MANDIRI' => 'Bank Mandiri', 'BRI' => 'Bank BRI',
            'BNI' => 'Bank BNI', 'BTN' => 'Bank BTN', 'SEABANK' => 'SeaBank', 'BANK_JAGO' => 'Bank Jago', 'ICBC' => 'Bank ICBC Indonesia',
            'CCB' => 'Bank CCB Indonesia', 'BANK_OF_CHINA' => 'Bank of China', default => $provider,
        };
    }

    public function settings(Request $request)
    {
        abort_if($request->user()->role === 'super_admin', 403);
        $frontliners = $request->user()->isOwner()
            ? User::where('outlet_id', $request->user()->outlet_id)->where('role', 'frontliner')->withCount('transactions')->withSum('transactions as sales_total', 'price')->withSum('transactions as profit_total', 'profit')->orderBy('name')->get()
            : collect();

        $selectedFrontliner = null;
        if ($request->user()->isOwner() && $request->filled('frontliner')) {
            $selectedFrontliner = $frontliners->firstWhere('id', (int) $request->frontliner);
            abort_unless($selectedFrontliner, 404);
            $selectedFrontliner->load(['transactions' => fn ($query) => $query->with('product')->latest()->limit(10)]);
        }

        return view('settings.index', compact('frontliners', 'selectedFrontliner'));
    }

    public function updatePassword(Request $request)
    {
        abort_if($request->user()->role === 'super_admin', 403);
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'string', 'min:8', 'confirmed']], ['current_password.current_password' => 'Password saat ini tidak sesuai.', 'password.confirmed' => 'Konfirmasi password baru tidak sama.']);
        $request->user()->update(['password' => $data['password']]);

        return back()->with('success', 'Password berhasil diubah. Gunakan password baru saat login berikutnya.');
    }

    public function updateEmail(Request $request)
    {
        abort_unless($request->user()->isOwner(), 403);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($request->user()->id)],
            'current_password' => ['required', 'current_password'],
        ], ['current_password.current_password' => 'Password saat ini tidak sesuai.']);
        $request->user()->update(['email' => strtolower($data['email'])]);

        return back()->with('success', 'Email pemulihan berhasil diperbarui.');
    }

    public function storeFrontliner(Request $request)
    {
        abort_unless($request->user()->isOwner(), 403);
        $request->merge(['login_id' => strtoupper((string) $request->login_id)]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'login_id' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9-]+$/', 'unique:users,login_id'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], ['login_id.regex' => 'ID login hanya boleh berisi huruf, angka, dan tanda hubung.']);
        User::create([
            'outlet_id' => $request->user()->outlet_id,
            'name' => $data['name'],
            'login_id' => strtoupper($data['login_id']),
            'email' => strtolower($request->user()->outlet->login_id).'.fl.'.Str::lower(Str::random(10)).'@outlet.docan.local',
            'password' => $data['password'],
            'role' => 'frontliner',
        ]);

        return back()->with('success', 'Akun Frontliner berhasil dibuat dengan ID login sendiri.');
    }

    public function destroyFrontliner(Request $request, User $frontliner)
    {
        abort_unless($request->user()->isOwner(), 403);
        abort_unless($frontliner->outlet_id === $request->user()->outlet_id && $frontliner->role === 'frontliner', 404);
        abort_if($frontliner->is($request->user()), 422);
        $frontliner->delete();

        return back()->with('success', 'Akun Frontliner dihapus.');
    }
}

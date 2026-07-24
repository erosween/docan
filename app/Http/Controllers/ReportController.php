<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Product;
use App\Models\BusinessEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    private const PHYSICAL_PROVIDERS = ['TELKOMSEL','BYU','INDOSAT','XL','TRI','SMARTFREN','AXIS'];
    private const RECHARGE_CHANNELS = ['DIGIPOS','SIDIVA','ISIMPEL','RITA','MULTI'];
    private const E_WALLETS = ['DANA','OVO','GOPAY','SHOPEEPAY','MAXIM','BRILINK','LINKAJA'];
    private const LOGOS = [
        'TELKOMSEL'=>'telkomsel.svg','BYU'=>'byu.svg','INDOSAT'=>'indosat.svg','XL'=>'xl.svg',
        'TRI'=>'tri.svg','SMARTFREN'=>'smartfren-official.svg','AXIS'=>'axis.svg',
        'DIGIPOS'=>'telkomsel.svg','SIDIVA'=>'xl.svg','ISIMPEL'=>'indosat.svg','RITA'=>'tri.svg','MULTI'=>'multi.svg',
        'DANA'=>'dana.webp','OVO'=>'ovo.webp','GOPAY'=>'gopay.webp','SHOPEEPAY'=>'shopeepay.webp',
        'MAXIM'=>'maxim.svg','BRILINK'=>'brilink.svg','LINKAJA'=>'linkaja.webp',
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
            $cashInOther = (int) BusinessEntry::where('outlet_id', $outletId)->where('type', 'cash-in')->whereBetween('entry_date', [$start, $end])->sum('amount');
            $cashOut = (int) BusinessEntry::where('outlet_id', $outletId)->whereIn('type', ['cash-out','purchase'])->whereBetween('entry_date', [$start, $end])->sum('amount');
            $stock = Product::where('outlet_id', $outletId)->selectRaw('COALESCE(SUM(stock),0) as units, COALESCE(SUM(stock * cost_price),0) as value')->first();
            return [
                'count'=>(int)$month->count,
                'turnover'=>(int)$month->turnover,
                'profit'=>(int)$month->profit,
                'stock'=>(int)$stock->units,
                'stockValue'=>(int)$stock->value,
                'salesCashIn'=>(int)$month->turnover,
                'otherCashIn'=>$cashInOther,
                'cashOut'=>$cashOut,
                'netCash'=>(int)$month->turnover + $cashInOther - $cashOut,
            ];
        });

        $weekly = collect([[1,7],[8,14],[15,21],[22,$end->day]])->map(function ($range, $index) use ($base, $period) {
            $from = $period->day($range[0])->startOfDay();
            $to = $period->day($range[1])->endOfDay();
            $row = (clone $base)->whereBetween('created_at', [$from, $to])
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(price),0) as turnover')->first();
            return ['label'=>'M'.($index+1),'range'=>$range[0].'–'.$range[1],'omset'=>(int)$row->turnover,'count'=>(int)$row->count];
        });

        $topProducts = (clone $base)->whereNotNull('product_id')->with('product')
            ->selectRaw('product_id, COALESCE(SUM(quantity),0) as sold, COUNT(*) as transaction_count, SUM(price) as revenue')
            ->groupBy('product_id')->orderByDesc('sold')->limit(5)->get();

        $today = Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outletId))
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(quantity),0) as item_count, COALESCE(SUM(price),0) as turnover, COALESCE(SUM(profit),0) as profit')
            ->first();

        return view('reports.index', [
            ...$summary,
            'monthCount'=>$summary['count'],'monthTurnover'=>$summary['turnover'],'monthProfit'=>$summary['profit'],
            'period'=>$period,'periodKey'=>$periodKey,'weeks'=>$weekly,'topProducts'=>$topProducts,
            'todaySummary'=>[
                'transactions'=>(int)$today->transaction_count,
                'items'=>(int)$today->item_count,
                'turnover'=>(int)$today->turnover,
                'profit'=>(int)$today->profit,
            ],
            'recent'=>(clone $base)->with('product')->latest()->limit(10)->get(),
        ]);
    }

    public function detail(Request $request, string $metric)
    {
        abort_if($request->user()->role === 'super_admin', 403);
        abort_unless(in_array($metric, ['turnover','profit','stock','stock-value'], true), 404);

        $outletId = $request->user()->outlet_id;
        try {
            $period = $request->filled('month')
                ? CarbonImmutable::createFromFormat('!Y-m', $request->string('month')->toString())
                : CarbonImmutable::now()->startOfMonth();
        } catch (\Throwable) {
            $period = CarbonImmutable::now()->startOfMonth();
        }
        $periodKey = $period->format('Y-m');
        $group = $request->string('group')->toString();
        if (! in_array($group, ['provider','recharge','wallet','accessory'], true)) $group = '';

        $meta = [
            'turnover'=>['title'=>'Rincian Omset','short'=>'Omset','description'=>'Nilai penjualan pada periode terpilih','money'=>true,'periodic'=>true],
            'profit'=>['title'=>'Rincian Laba','short'=>'Laba','description'=>'Keuntungan penjualan setelah dikurangi modal','money'=>true,'periodic'=>true],
            'stock'=>['title'=>'Rincian Total Stok','short'=>'Stok','description'=>'Posisi stok yang tersedia saat ini','money'=>false,'periodic'=>false],
            'stock-value'=>['title'=>'Rincian Nilai Modal Stok','short'=>'Modal stok','description'=>'Nilai stok berdasarkan harga modal saat ini','money'=>true,'periodic'=>false],
        ][$metric];

        $transactionRows = Transaction::query()
            ->whereHas('user', fn ($query) => $query->where('outlet_id', $outletId))
            ->whereBetween('created_at', [$period->startOfMonth(), $period->endOfMonth()])
            ->select([DB::raw('UPPER(provider) as provider_key'), DB::raw("COALESCE(product_type, '') as type_key"), DB::raw("COALESCE(transaction_action, '') as action_key")])
            ->selectRaw('COALESCE(SUM(price),0) as turnover, COALESCE(SUM(profit),0) as profit, COALESCE(SUM(quantity),0) as units')
            ->groupByRaw("UPPER(provider), COALESCE(product_type, ''), COALESCE(transaction_action, '')")
            ->get();
        $productRows = Product::query()->where('outlet_id', $outletId)
            ->select([DB::raw('UPPER(operator) as provider_key'), DB::raw("COALESCE(category, '') as type_key")])
            ->selectRaw('COALESCE(SUM(stock),0) as stock, COALESCE(SUM(stock * cost_price),0) as stock_value')
            ->groupByRaw("UPPER(operator), COALESCE(category, '')")->get();

        $valueOf = fn ($rows) => (int) $rows->sum(match ($metric) {
            'turnover'=>'turnover','profit'=>'profit','stock'=>'stock',default=>'stock_value',
        });
        $source = in_array($metric, ['turnover','profit'], true) ? $transactionRows : $productRows;
        $cardsByGroup = [
            'provider'=>$this->physicalMetricCards($source, $valueOf, $meta['short']),
            'recharge'=>$this->balanceMetricCards($source, $valueOf, self::RECHARGE_CHANNELS, 'recharge', $meta['short']),
            'wallet'=>$this->balanceMetricCards($source, $valueOf, self::E_WALLETS, 'wallet', $meta['short']),
            'accessory'=>$this->accessoryMetricCards($source, $valueOf, $meta['short']),
        ];
        $groupMeta = [
            'provider'=>['title'=>'Produk Provider','description'=>'Voucher fisik dan kartu paket','icon'=>'▤'],
            'recharge'=>['title'=>'Pulsa & Paket Tembak','description'=>'Saldo channel, pulsa, PPOB dan digital','icon'=>'ϟ'],
            'wallet'=>['title'=>'E-Wallet','description'=>'Top up dan layanan keuangan','icon'=>'▣'],
            'accessory'=>['title'=>'Aksesoris','description'=>'Kabel, charger, casing dan lainnya','icon'=>'⌁'],
        ];
        foreach ($groupMeta as $key => &$item) {
            $item['value'] = $key === 'provider'
                ? (int) ($cardsByGroup[$key][0]['value'] ?? 0)
                : (int) collect($cardsByGroup[$key])->sum('value');
        }
        unset($item);

        return view('reports.detail', compact('metric','meta','period','periodKey','group','groupMeta','cardsByGroup'));
    }

    private function physicalMetricCards($rows, callable $valueOf, string $label): array
    {
        $cards = collect(self::PHYSICAL_PROVIDERS)->map(function (string $provider) use ($rows, $valueOf, $label) {
            $providerRows = $rows->where('provider_key', $provider);
            $sa = $providerRows->filter(fn ($row) => str_contains(strtolower((string) $row->type_key), 'kartu'));
            $pv = $providerRows->reject(fn ($row) => str_contains(strtolower((string) $row->type_key), 'kartu'));
            return ['key'=>$provider,'title'=>$provider === 'BYU' ? 'by.U' : ucfirst(strtolower($provider)),
                'logo'=>self::LOGOS[$provider],'value'=>$valueOf($providerRows),'lines'=>[
                    ['label'=>$label.' Voucher Fisik','value'=>$valueOf($pv)],['label'=>$label.' Kartu Paket','value'=>$valueOf($sa)],
                ]];
        })->all();
        array_unshift($cards, ['key'=>'ALL','title'=>'Semua Provider','logo'=>null,
            'value'=>$valueOf($rows->whereIn('provider_key', self::PHYSICAL_PROVIDERS)),'lines'=>[
                ['label'=>$label.' Voucher Fisik','value'=>collect($cards)->sum(fn ($card) => $card['lines'][0]['value'])],
                ['label'=>$label.' Kartu Paket','value'=>collect($cards)->sum(fn ($card) => $card['lines'][1]['value'])],
            ]]);
        return $cards;
    }

    private function balanceMetricCards($rows, callable $valueOf, array $providers, string $group, string $label): array
    {
        $actionLabels = ['receive_payment'=>'Terima pembayaran','customer_topup'=>'Top up pelanggan','cash_withdrawal'=>'Tarik tunai','bill_payment'=>'Bayar tagihan'];
        return collect($providers)->map(function (string $provider) use ($rows, $valueOf, $group, $label, $actionLabels) {
            $providerRows = $rows->where('provider_key', $provider);
            $hasActions = $group === 'wallet' && $providerRows->contains(fn ($row) => isset($row->action_key) && $row->action_key !== '');
            $lines = $hasActions
                ? collect($actionLabels)->map(fn ($name, $action) => ['label'=>$name,'value'=>$valueOf($providerRows->where('action_key', $action))])->values()->all()
                : [['label'=>$label.' tersedia','value'=>$valueOf($providerRows)]];
            return ['key'=>$provider,'title'=>$this->displayReportName($provider),'logo'=>self::LOGOS[$provider] ?? null,
                'value'=>$valueOf($providerRows),'lines'=>$lines];
        })->all();
    }

    private function accessoryMetricCards($rows, callable $valueOf, string $label): array
    {
        $items = $rows->filter(fn ($row) => $row->provider_key === 'AKSESORIS'
            || str_contains(strtolower((string) $row->type_key), 'aksesoris'));
        return [['key'=>'AKSESORIS','title'=>'Semua Aksesoris','logo'=>null,'value'=>$valueOf($items),
            'lines'=>[['label'=>$label.' aksesoris','value'=>$valueOf($items)]]]];
    }

    private function displayReportName(string $provider): string
    {
        return match ($provider) {
            'DIGIPOS'=>'DigiPOS','ISIMPEL'=>'iSimpel','GOPAY'=>'GoPay','SHOPEEPAY'=>'ShopeePay',
            'BRILINK'=>'BRILink','LINKAJA'=>'LinkAja',default=>$provider,
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
        $data=$request->validate(['current_password'=>['required','current_password'],'password'=>['required','string','min:8','confirmed']],['current_password.current_password'=>'Password saat ini tidak sesuai.','password.confirmed'=>'Konfirmasi password baru tidak sama.']);
        $request->user()->update(['password'=>$data['password']]);
        return back()->with('success','Password berhasil diubah. Gunakan password baru saat login berikutnya.');
    }

    public function storeFrontliner(Request $request)
    {
        abort_unless($request->user()->isOwner(), 403);
        $request->merge(['login_id' => strtoupper((string) $request->login_id)]);
        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'login_id' => ['required','string','max:80','regex:/^[A-Z0-9-]+$/','unique:users,login_id'],
            'password' => ['required','string','min:8','max:72','confirmed'],
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

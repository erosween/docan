<?php

namespace App\Http\Controllers;

use App\Models\Denomination;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    private const OPERATORS = ['TELKOMSEL', 'BYU', 'INDOSAT', 'XL', 'TRI', 'SMARTFREN', 'AXIS', 'DANA', 'OVO', 'GOPAY', 'SHOPEEPAY', 'PLN', 'BRILINK', 'PPOB'];

    private const CATEGORIES = ['Pulsa Reguler', 'Pulsa Data', 'Saldo E-Wallet', 'Token PLN', 'Transfer', 'Tarik Tunai', 'Setor Tunai', 'BPJS Kesehatan', 'PDAM', 'Internet & TV', 'Pascabayar', 'Pajak & PBB'];

    private const ANALYTIC_OPERATORS = ['TELKOMSEL', 'BYU', 'XL', 'TRI', 'INDOSAT', 'SMARTFREN', 'AXIS'];

    public function dashboard(Request $request)
    {
        $this->guard($request);
        $page = $request->route('page', 'dashboard');
        $outlets = Outlet::orderBy('name')->get();
        $transactions = $outletDirectory = $catalogProducts = null;
        $denominations = collect();
        if ($page === 'transactions') {
            $query = Transaction::with(['user.outlet', 'product'])->latest();
            if ($request->filled('outlet')) {
                $query->whereHas('user', fn ($q) => $q->where('outlet_id', $request->outlet));
            }if ($request->filled('date_from')) {
                $query->where('created_at', '>=', $request->date_from.' 00:00:00');
            }if ($request->filled('date_to')) {
                $query->where('created_at', '<=', $request->date_to.' 23:59:59');
            }$transactions = $query->paginate(20)->withQueryString();
        }
        if ($page === 'outlets') {
            $outletDirectory = Outlet::with(['users' => fn ($query) => $query->whereIn('role', ['owner', 'frontliner', 'outlet'])->withCount('transactions')->withSum('transactions as sales_total', 'price')->withSum('transactions as profit_total', 'profit')->orderBy('role')->orderBy('name')])->withCount('products')->orderBy('name')->paginate(30, ['*'], 'outlet_page')->withQueryString();
        }
        if ($page === 'denominations') {
            $catalogQuery = Product::with('outlet')->orderBy('outlet_id')->orderBy('operator')->orderBy('category')->orderBy('name');
            if ($request->filled('product_outlet')) {
                $catalogQuery->where('outlet_id', $request->product_outlet);
            }if ($request->filled('product_search')) {
                $catalogQuery->where(fn ($q) => $q->where('name', 'like', '%'.$request->product_search.'%')->orWhere('operator', 'like', '%'.$request->product_search.'%'));
            }$catalogProducts = $catalogQuery->paginate(30, ['*'], 'products_page')->withQueryString();
            $denominations = Denomination::orderBy('operator')->orderBy('category')->orderBy('nominal')->get();
        }

        $analytics = ['todayCount' => 0, 'todayTurnover' => 0, 'todayProfit' => 0, 'monthCount' => 0, 'monthTurnover' => 0, 'monthProfit' => 0, 'trend' => collect(), 'topOutlets' => collect(), 'outletUsers' => 0, 'operatorSales' => collect(), 'starterShare' => collect(), 'rechargeShare' => collect(), 'voucherComparison' => collect()];
        if ($page === 'dashboard') {
            // Data dashboard berisi Collection/model dan tidak boleh diserialisasi
            // ke Redis dengan allowed_classes=false. Cache per-request menghindari
            // incomplete object tanpa melemahkan keamanan cache produksi.
            $analytics = Cache::store('array')->remember('admin:dashboard:analytics', 30, function () {
                $today = Transaction::whereBetween('created_at', [today(), today()->endOfDay()])->selectRaw('COUNT(*) as count,COALESCE(SUM(price),0) as turnover,COALESCE(SUM(profit),0) as profit')->first();
                $monthRange = [now()->startOfMonth(), now()->endOfMonth()];
                $month = Transaction::whereBetween('created_at', $monthRange)->selectRaw('COUNT(*) as count,COALESCE(SUM(price),0) as turnover,COALESCE(SUM(profit),0) as profit')->first();
                $daily = Transaction::whereBetween('created_at', [today()->subDays(6), today()->endOfDay()])->selectRaw('DATE(created_at) as sale_date,COUNT(*) as count,COALESCE(SUM(price),0) as turnover')->groupByRaw('DATE(created_at)')->get()->keyBy('sale_date');
                $trend = collect(range(6, 0))->map(function ($offset) use ($daily) {
                    $date = today()->subDays($offset);
                    $row = $daily->get($date->format('Y-m-d'));

                    return ['label' => $date->translatedFormat('D'), 'date' => $date->format('d M'), 'turnover' => (int) ($row->turnover ?? 0), 'count' => (int) ($row->count ?? 0)];
                });
                $topOutlets = Outlet::query()->leftJoin('users', 'users.outlet_id', '=', 'outlets.id')->leftJoin('transactions', 'transactions.user_id', '=', 'users.id')->selectRaw('outlets.id,outlets.name,COUNT(transactions.id) as transaction_count,COALESCE(SUM(transactions.price),0) as turnover')->groupBy('outlets.id', 'outlets.name')->orderByDesc('turnover')->limit(5)->get();
                $operatorRaw = Transaction::whereIn('provider', self::ANALYTIC_OPERATORS)->whereBetween('created_at', $monthRange)->selectRaw('provider,COALESCE(SUM(quantity),0) as sales,COALESCE(SUM(price),0) as turnover,COALESCE(SUM(profit),0) as profit')->groupBy('provider')->get()->keyBy('provider');
                $operatorSales = collect(self::ANALYTIC_OPERATORS)->map(fn ($operator) => (object) ['operator' => $operator, 'sales' => (int) ($operatorRaw[$operator]->sales ?? 0), 'turnover' => (int) ($operatorRaw[$operator]->turnover ?? 0), 'profit' => (int) ($operatorRaw[$operator]->profit ?? 0)]);
                $starterRaw = Transaction::whereIn('provider', self::ANALYTIC_OPERATORS)->where('product_type', 'Kartu Paket')->whereBetween('created_at', $monthRange)->selectRaw('provider,COALESCE(SUM(quantity),0) as value')->groupBy('provider')->pluck('value', 'provider');
                $rechargeRaw = Transaction::whereIn('provider', self::ANALYTIC_OPERATORS)->whereIn('product_type', ['Pulsa Reguler', 'Pulsa Data'])->whereBetween('created_at', $monthRange)->selectRaw('provider,COALESCE(SUM(price),0) as value')->groupBy('provider')->pluck('value', 'provider');
                $starterShare = collect(self::ANALYTIC_OPERATORS)->map(fn ($operator) => (object) ['operator' => $operator, 'value' => (int) ($starterRaw[$operator] ?? 0)]);
                $rechargeShare = collect(self::ANALYTIC_OPERATORS)->map(fn ($operator) => (object) ['operator' => $operator, 'value' => (int) ($rechargeRaw[$operator] ?? 0)]);
                $voucherComparison = Transaction::query()->join('products', 'products.id', '=', 'transactions.product_id')->whereIn('transactions.provider', self::ANALYTIC_OPERATORS)->where('transactions.product_type', 'Voucher Internet')->whereBetween('transactions.created_at', $monthRange)->selectRaw('transactions.provider,products.quota_gb,products.validity_days,COALESCE(SUM(transactions.quantity),0) as sales,COALESCE(SUM(transactions.price),0) as turnover,COALESCE(SUM(transactions.profit),0) as profit')->groupBy('transactions.provider', 'products.quota_gb', 'products.validity_days')->orderBy('products.quota_gb')->orderBy('products.validity_days')->get();

                return ['todayCount' => (int) $today->count, 'todayTurnover' => (int) $today->turnover, 'todayProfit' => (int) $today->profit, 'monthCount' => (int) $month->count, 'monthTurnover' => (int) $month->turnover, 'monthProfit' => (int) $month->profit, 'trend' => $trend, 'topOutlets' => $topOutlets, 'outletUsers' => User::whereIn('role', ['owner', 'frontliner', 'outlet'])->count(), 'operatorSales' => $operatorSales, 'starterShare' => $starterShare, 'rechargeShare' => $rechargeShare, 'voucherComparison' => $voucherComparison];
            });
        }

        return view('admin.dashboard', [...$analytics, 'page' => $page, 'transactions' => $transactions, 'outlets' => $outlets, 'outletDirectory' => $outletDirectory, 'denominations' => $denominations, 'operators' => self::OPERATORS, 'categories' => self::CATEGORIES, 'catalogProducts' => $catalogProducts, 'turnover' => 0, 'profit' => 0, 'validityHeaders' => [1, 2, 3, 5, 7, 14, 28]]);
    }

    public function storeOutlet(Request $request)
    {
        $this->guard($request);
        $request->merge(['login_id' => strtoupper((string) $request->login_id)]);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'login_id' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/', 'unique:outlets,login_id'], 'password' => ['required', 'string', 'min:8', 'max:72']], ['login_id.regex' => 'ID Outlet hanya boleh berisi huruf, angka, dan tanda hubung.']);
        DB::transaction(function () use ($data) {
            $outlet = Outlet::create(['name' => $data['name'], 'login_id' => $data['login_id'], 'code' => $data['login_id']]);
            $this->applyStarterCatalog($outlet);
            User::create(['outlet_id' => $outlet->id, 'name' => 'Owner '.$data['name'], 'email' => strtolower($data['login_id']).'.'.str()->random(8).'@outlet.docan.local', 'login_id' => $data['login_id'], 'password' => $data['password'], 'role' => 'owner']);
        });

        return back()->with('success', 'Outlet dan akun login berhasil dibuat.')->with('credentials', ['login_id' => $data['login_id'], 'password' => $data['password']]);
    }

    public function storeUser(Request $request)
    {
        $this->guard($request);
        $data = $request->validate(['outlet_id' => ['required', 'exists:outlets,id'], 'name' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'min:8', 'max:72']]);
        $outlet = Outlet::findOrFail($data['outlet_id']);
        $email = strtolower($outlet->login_id).'.'.str()->random(8).'@outlet.docan.local';
        $ownerSequence = $outlet->users()->whereIn('role', ['owner', 'outlet'])->count() + 1;
        $loginId = $ownerSequence === 1 ? $outlet->login_id : sprintf('%s-OWN%02d', $outlet->login_id, $ownerSequence);
        DB::transaction(function () use ($data, $outlet, $email, $loginId) {
            if (! $outlet->products()->exists()) {
                $this->applyStarterCatalog($outlet);
            }User::create([...$data, 'email' => $email, 'login_id' => $loginId, 'role' => 'owner']);
        });

        return back()->with('success', 'Akun outlet berhasil dibuat.')->with('credentials', ['login_id' => $loginId, 'password' => $data['password']]);
    }

    public function syncOutletCatalog(Request $request, Outlet $outlet)
    {
        $this->guard($request);
        if ($outlet->products()->exists()) {
            return back()->withErrors(['catalog' => 'Sinkronisasi hanya tersedia saat outlet baru dibuat. Katalog dan stok outlet lama tidak diubah.']);
        }$added = $this->applyStarterCatalog($outlet);

        return back()->with('success', "{$added} produk katalog awal ditambahkan ke {$outlet->name}.");
    }

    public function importOutlets(Request $request)
    {
        $this->guard($request);
        $request->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);
        $handle = fopen($request->file('csv')->getRealPath(), 'r');
        $header = fgetcsv($handle);
        $header = array_map(fn ($value) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))), $header ?: []);
        $required = ['outlet_name', 'outlet_id', 'user_name', 'password'];
        if ($header !== $required) {
            fclose($handle);

            return back()->withErrors(['csv' => 'Format kolom tidak sesuai. Gunakan file contoh CSV dari Docan.']);
        }
        $created = 0;
        $errors = [];
        $line = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }$values = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));
            $values['outlet_id'] = strtoupper(trim($values['outlet_id']));
            $values['password'] = trim($values['password']) ?: 'Docan123!';
            $validator = Validator::make($values, ['outlet_name' => ['required', 'string', 'max:120'], 'outlet_id' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/'], 'user_name' => ['required', 'string', 'max:120'], 'password' => ['required', 'string', 'min:8', 'max:72']]);
            if ($validator->fails()) {
                $errors[] = 'Baris '.$line.': '.$validator->errors()->first();

                continue;
            }
            try {
                DB::transaction(function () use ($values) {
                    $outlet = Outlet::firstOrCreate(['login_id' => $values['outlet_id']], ['code' => $values['outlet_id'], 'name' => $values['outlet_name']]);
                    if (! $outlet->products()->exists()) {
                        $this->applyStarterCatalog($outlet);
                    }User::create(['outlet_id' => $outlet->id, 'name' => $values['user_name'], 'email' => strtolower($values['outlet_id']).'.'.str()->random(8).'@outlet.docan.local', 'login_id' => $values['outlet_id'], 'password' => $values['password'], 'role' => 'owner']);
                });
                $created++;
            } catch (\Throwable $exception) {
                $errors[] = 'Baris '.$line.': gagal disimpan.';
            }
        }fclose($handle);

        return back()->with('success', "{$created} akun outlet berhasil diimpor.")->with('import_errors', $errors);
    }

    public function outletImportExample(Request $request)
    {
        $this->guard($request);

        return response()->streamDownload(function () {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['outlet_name', 'outlet_id', 'user_name', 'password']);
            fputcsv($file, ['Outlet Antasari', 'ATS-001', 'Kasir Antasari', 'Docan123!']);
            fputcsv($file, ['Outlet Panakkukang', 'PNK-001', 'Kasir Panakkukang', 'Docan123!']);
            fclose($file);
        }, 'contoh-import-outlet-docan.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportOutlets(Request $request)
    {
        $this->guard($request);

        return response()->streamDownload(function () {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['ID Outlet', 'Nama Outlet', 'Nama Pengguna', 'Email Internal', 'Role', 'Jumlah Produk', 'Tanggal Dibuat']);
            Outlet::with(['users' => fn ($query) => $query->orderBy('name')])->withCount('products')->orderBy('id')->chunkById(200, function ($outlets) use ($file) {
                foreach ($outlets as $outlet) {
                    if ($outlet->users->isEmpty()) {
                        fputcsv($file, [$outlet->login_id, $outlet->name, '', '', '', $outlet->products_count, $outlet->created_at?->format('Y-m-d H:i:s')]);
                    }foreach ($outlet->users as $user) {
                        fputcsv($file, [$outlet->login_id, $outlet->name, $user->name, $user->email, $user->role, $outlet->products_count, $outlet->created_at?->format('Y-m-d H:i:s')]);
                    }
                }
            });
            fclose($file);
        }, 'outlet-dan-user-docan-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function applyStarterCatalog(Outlet $outlet): int
    {
        $products = json_decode(file_get_contents(database_path('data/voucher_fisik.json')), true);
        $added = 0;
        foreach ($products as $index => $product) {
            preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*GB/i', $product['name'], $quota);
            preg_match('/([0-9]+)\s*(?:D|Hari)/i', $product['name'], $days);
            $attributes = ['outlet_id' => $outlet->id, 'operator' => $product['operator'], 'category' => 'Voucher Internet', 'name' => $product['name'], 'cost_price' => $product['cost_price']];
            $values = [...$product, 'category' => 'Voucher Internet', 'quota_gb' => isset($quota[1]) ? str_replace(',', '.', $quota[1]) : null, 'validity_days' => $days[1] ?? null, 'sku' => sprintf('VF-%04d', $index + 1), 'is_active' => true];
            $model = Product::firstOrCreate($attributes, $values);
            if ($model->wasRecentlyCreated) {
                $added++;
            }
        }
        foreach (self::ANALYTIC_OPERATORS as $operator) {
            $model = Product::firstOrCreate(['outlet_id' => $outlet->id, 'operator' => $operator, 'category' => 'Kartu Paket', 'quota_gb' => 3, 'validity_days' => 30, 'cost_price' => 0], ['name' => '3GB · 30D', 'selling_price' => 0, 'stock' => 0, 'is_active' => true]);
            if ($model->wasRecentlyCreated) {
                $added++;
            }
        }

        return $added;
    }

    public function export(Request $request)
    {
        $this->guard($request);
        $query = Transaction::with(['user.outlet'])->latest();
        if ($request->filled('outlet')) {
            $query->whereHas('user', fn ($q) => $q->where('outlet_id', $request->outlet));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return response()->streamDownload(function () use ($query) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Tanggal', 'Outlet', 'Kasir', 'Operator', 'Produk', 'Nomor', 'Qty', 'Modal', 'Harga Jual', 'Laba']);
            $query->chunk(500, function ($rows) use ($file) {
                foreach ($rows as $row) {
                    fputcsv($file, [$row->created_at->format('Y-m-d H:i:s'), $row->user?->outlet?->name, $row->user?->name, $row->provider, $row->product?->name ?? $row->product_type, $row->customer_number, $row->quantity ?? 1, $row->cost_price, $row->price, $row->profit]);
                }
            });
            fclose($file);
        }, 'transaksi-docan-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function exportProducts(Request $request)
    {
        $this->guard($request);
        $query = Product::with('outlet')->orderBy('outlet_id')->orderBy('operator')->orderBy('name');
        if ($request->filled('product_outlet')) {
            $query->where('outlet_id', $request->product_outlet);
        }if ($request->filled('product_search')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$request->product_search.'%')->orWhere('operator', 'like', '%'.$request->product_search.'%'));
        }

        return response()->streamDownload(function () use ($query) {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['ID Outlet', 'Nama Outlet', 'Operator', 'Nama Produk', 'Kategori', 'Kuota GB', 'Masa Aktif', 'Modal', 'Harga Jual', 'Laba per Item', 'Stok', 'Status']);
            $query->chunk(500, function ($rows) use ($file) {
                foreach ($rows as $row) {
                    fputcsv($file, [$row->outlet?->login_id, $row->outlet?->name, $row->operator, $row->name, $row->category, $row->quota_gb, $row->validity_days, $row->cost_price, $row->selling_price, $row->profit, $row->stock, $row->is_active ? 'Aktif' : 'Nonaktif']);
                }
            });
            fclose($file);
        }, 'master-produk-outlet-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function storeDenomination(Request $request)
    {
        $this->guard($request);
        $data = $this->validatedDenomination($request);
        Denomination::create([...$data, 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Denom berhasil ditambahkan.');
    }

    public function updateDenomination(Request $request, Denomination $denomination)
    {
        $this->guard($request);
        $denomination->update([...$this->validatedDenomination($request), 'is_active' => $request->boolean('is_active')]);

        return back()->with('success', 'Denom berhasil diperbarui.');
    }

    public function destroyDenomination(Request $request, Denomination $denomination)
    {
        $this->guard($request);
        $denomination->delete();

        return back()->with('success', 'Denom dihapus.');
    }

    private function validatedDenomination(Request $request): array
    {
        $request->merge(['nominal' => preg_replace('/\D/','',(string) $request->nominal)]);

        return $request->validate(['operator' => ['required', Rule::in(self::OPERATORS)], 'category' => ['required', Rule::in(self::CATEGORIES)], 'nominal' => ['required', 'integer', 'min:1000', 'max:10000000']]);
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->role === 'super_admin',403);
    }
}

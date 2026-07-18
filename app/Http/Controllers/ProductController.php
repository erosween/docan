<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private const E_WALLETS = ['LINKAJA','DANA','OVO','GOPAY','SHOPEEPAY','MAXIM','BRILINK'];
    private const PHYSICAL_OPERATORS = ['TELKOMSEL','BYU','INDOSAT','XL','TRI','SMARTFREN','AXIS'];
    private const RECHARGE_CHANNELS = ['DIGIPOS','SIDIVA','ISIMPEL','RITA','MULTI'];
    private const OPERATORS = ['TELKOMSEL','BYU','INDOSAT','XL','TRI','SMARTFREN','AXIS','AKSESORIS','DIGIPOS','SIDIVA','ISIMPEL','RITA','MULTI','LINKAJA','DANA','OVO','GOPAY','SHOPEEPAY','MAXIM','BRILINK'];
    private const CATEGORIES = ['Voucher Internet','Kartu Paket','Saldo Provider','Aksesoris HP'];
    private const VALIDITY_DAYS = [1,2,3,5,7,14,28,30];
    private const LOGOS = [
        'TELKOMSEL'=>'telkomsel.svg','BYU'=>'byu.svg','INDOSAT'=>'indosat.svg',
        'XL'=>'xl.svg','TRI'=>'tri.svg','SMARTFREN'=>'smartfren.svg',
        'AXIS'=>'axis.svg',
        'DIGIPOS'=>'telkomsel.svg','SIDIVA'=>'xl.svg','ISIMPEL'=>'indosat.svg','RITA'=>'tri.svg','MULTI'=>'multi.svg',
        'DANA'=>'dana.svg','OVO'=>'ovo.svg','GOPAY'=>'gopay.svg','SHOPEEPAY'=>'shopeepay.svg',
        'MAXIM'=>'maxim.svg','BRILINK'=>'brilink.svg','LINKAJA'=>'linkaja.svg',
    ];

    public function index(Request $request)
    {
        if ($request->user()->isFrontliner() && ! $request->boolean('stock')) {
            return redirect()->route('products.index', ['stock' => 1]);
        }
        $query = Product::where('outlet_id', $request->user()->outlet_id);
        $this->applyGroupFilter($query, $request->string('group')->toString());
        if ($request->filled('operator')) $this->applyOperatorFilter($query, $request->operator, $request->string('group')->toString());
        if ($request->filled('q')) $query->where('name', 'like', '%'.$request->q.'%');
        if ($request->sort === 'lowest') {
            $query->orderBy('stock')->orderBy('name');
        } elseif ($request->sort === 'bestseller') {
            $query->withSum('transactions as sold_quantity', 'quantity')
                ->orderByDesc('sold_quantity')->orderBy('name');
        } else {
            $query->latest();
        }
        $products = $query->paginate(12)->withQueryString();
        $productGroups = $products->getCollection()->groupBy(fn (Product $product) =>
            implode('|', [$product->operator, $product->category, $product->quota_gb, $product->validity_days, $product->name])
        );
        $baseQuery = Product::where('outlet_id', $request->user()->outlet_id);
        $stats = (clone $baseQuery)
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN category <> 'Saldo Provider' THEN stock ELSE 0 END),0) as stock, COALESCE(SUM(stock * cost_price),0) as value")->first();
        $detailStatsQuery = clone $baseQuery;
        $this->applyGroupFilter($detailStatsQuery, $request->string('group')->toString());
        if ($request->filled('operator')) $this->applyOperatorFilter($detailStatsQuery, $request->operator, $request->string('group')->toString());
        $isBalanceGroup = in_array($request->string('group')->toString(), ['recharge','wallet'], true);
        $detailStats = $detailStatsQuery
            ->selectRaw($isBalanceGroup
                ? "COUNT(*) as total, COALESCE(SUM(stock),0) as stock, COALESCE(SUM(stock),0) as value"
                : "COUNT(*) as total, COALESCE(SUM(CASE WHEN category <> 'Saldo Provider' THEN stock ELSE 0 END),0) as stock, COALESCE(SUM(stock * cost_price),0) as value")
            ->first();
        $stockRows = (clone $baseQuery)
            ->select('operator', 'category')
            ->selectRaw('COUNT(*) as product_count, COALESCE(SUM(stock),0) as stock')
            ->groupBy('operator', 'category')->get();
        $providerSummaries = collect(self::PHYSICAL_OPERATORS)
            ->map(function ($operator) use ($stockRows) {
                $rows = $stockRows->where('operator', $operator);
                return [
                    'operator' => $operator,
                    'logo' => self::LOGOS[$operator] ?? null,
                    'products' => (int) $rows->whereIn('category', ['Voucher Internet', 'Kartu Paket'])->sum('product_count'),
                    'voucher' => (int) optional($rows->firstWhere('category', 'Voucher Internet'))->stock,
                    'package' => (int) optional($rows->firstWhere('category', 'Kartu Paket'))->stock,
                    'channel' => match ($operator) {
                        'TELKOMSEL', 'BYU' => 'DigiPOS',
                        'XL', 'AXIS', 'SMARTFREN' => 'SIDIVA',
                        'INDOSAT' => 'iSimpel',
                        'TRI' => 'RITA',
                        default => 'MULTI',
                    },
                    'balance' => (int) optional($rows->firstWhere('category', 'Saldo Provider'))->stock,
                ];
            });
        $serviceGroups = [
            'provider' => (clone $baseQuery)->whereIn('category', ['Voucher Internet', 'Kartu Paket'])->count(),
            'recharge' => (clone $baseQuery)->where('category', 'Saldo Provider')->whereNotIn('operator', self::E_WALLETS)->count(),
            'wallet' => (clone $baseQuery)->where('category', 'Saldo Provider')->whereIn('operator', self::E_WALLETS)->count(),
            'accessory' => (clone $baseQuery)->where('operator', 'AKSESORIS')->count(),
        ];
        $serviceBalance = (int) (clone $baseQuery)->where('category', 'Saldo Provider')->whereNotIn('operator', self::E_WALLETS)->sum('stock');
        $balanceSummaries = $this->balanceSummaries($baseQuery, $request->string('group')->toString());
        return view('products.index', compact('products', 'productGroups', 'stats', 'detailStats', 'providerSummaries', 'serviceGroups', 'serviceBalance', 'balanceSummaries') + ['operators'=>self::OPERATORS]);
    }

    private function applyGroupFilter($query, string $group): void
    {
        match ($group) {
            'provider' => $query->whereIn('category', ['Voucher Internet', 'Kartu Paket']),
            'recharge' => $query->where('category', 'Saldo Provider')->whereNotIn('operator', self::E_WALLETS),
            'wallet' => $query->where('category', 'Saldo Provider')->whereIn('operator', self::E_WALLETS),
            'accessory' => $query->where('operator', 'AKSESORIS'),
            default => null,
        };
    }

    private function applyOperatorFilter($query, string $operator, string $group): void
    {
        if ($group === 'recharge') {
            $query->whereIn('operator', $this->balanceOperatorAliases($operator));
            return;
        }
        $query->where('operator', $operator);
    }

    private function balanceOperatorAliases(string $operator): array
    {
        return match ($operator) {
            'DIGIPOS' => ['DIGIPOS','TELKOMSEL','BYU'],
            'SIDIVA' => ['SIDIVA','XL','AXIS','SMARTFREN'],
            'ISIMPEL' => ['ISIMPEL','INDOSAT'],
            'RITA' => ['RITA','TRI'],
            'MULTI' => ['MULTI'],
            default => [$operator],
        };
    }

    private function balanceSummaries($baseQuery, string $group)
    {
        $items = match ($group) {
            'wallet' => collect(self::E_WALLETS),
            'recharge' => collect(self::RECHARGE_CHANNELS),
            default => collect(),
        };

        return $items->map(function (string $operator) use ($baseQuery, $group) {
            $query = (clone $baseQuery)->where('category', 'Saldo Provider');
            $this->applyOperatorFilter($query, $operator, $group);
            return [
                'operator' => $operator,
                'name' => $this->displayChannelName($operator),
                'logo' => self::LOGOS[$operator] ?? 'docan-service.svg',
                'products' => (clone $query)->count(),
                'balance' => (int) $query->sum('stock'),
            ];
        });
    }

    public function create(Request $request)
    {
        if ($request->filled('source')) {
            $source = Product::findOrFail($request->integer('source'));
            $this->authorizeOutlet($request, $source);
            $variant = $source->replicate();
            $variant->stock = 0;
            $variant->is_active = true;

            return $this->formView($variant);
        }

        return $this->formView(new Product);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($request->boolean('variant')) {
            $source = Product::findOrFail($request->integer('source_id'));
            $this->authorizeOutlet($request, $source);
            $data = array_replace($data, [
                'operator'=>$source->operator,
                'category'=>$source->category,
                'name'=>$source->name,
                'quota_gb'=>$source->quota_gb,
                'validity_days'=>$source->validity_days,
            ]);
        }
        $this->ensureNotDuplicate($request, $data);
        if (! $request->boolean('variant')) {
            $data['name'] = $this->productName($data);
        }
        Product::create([...$data, 'outlet_id'=>$request->user()->outlet_id, 'is_active'=>$request->boolean('is_active')]);
        $returnGroup = $request->string('return_group')->toString();
        $returnOperator = $request->string('return_operator')->toString();
        $allowedReturnOperators = array_merge(self::OPERATORS, self::E_WALLETS, self::RECHARGE_CHANNELS);
        $redirect = $request->boolean('variant')
            ? route('products.index', ['operator'=>$data['operator']])
            : (in_array($returnGroup, ['provider','recharge','wallet','accessory'], true) && in_array($returnOperator, $allowedReturnOperators, true)
                ? route('products.index', ['group'=>$returnGroup, 'operator'=>$returnOperator])
                : route('products.index'));
        return redirect($redirect)
            ->with('success', $request->boolean('variant') ? 'Varian harga baru berhasil ditambahkan.' : 'Produk berhasil ditambahkan.');
    }

    public function edit(Request $request, Product $product)
    {
        $this->authorizeOutlet($request, $product);
        return $this->formView($product);
    }

    public function update(Request $request, Product $product)
    {
        $this->authorizeOutlet($request, $product);
        $data = $this->validated($request);
        $data = array_replace($data, [
            'operator'=>$product->operator,
            'category'=>$product->category,
            'name'=>$product->name,
            'quota_gb'=>$product->quota_gb,
            'validity_days'=>$product->validity_days,
        ]);
        $this->ensureNotDuplicate($request, $data, $product->id);
        $product->update([...$data, 'is_active'=>$request->boolean('is_active')]);
        return redirect()->route('products.index')->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Request $request, Product $product)
    {
        $this->authorizeOutlet($request, $product);
        $product->delete();
        return back()->with('success', 'Produk berhasil dihapus.');
    }

    public function addStock(Request $request,Product $product)
    {
        abort_unless($request->user()->isOwner(), 403);
        $this->authorizeOutlet($request,$product);
        $max=$product->category==='Saldo Provider'?1000000000000:10000;
        $request->merge(['quantity'=>preg_replace('/\D/','',(string)$request->quantity)]);
        $data=$request->validate(['quantity'=>['required','integer','min:1','max:'.$max]]);$product->increment('stock',$data['quantity']);
        if($request->expectsJson())return response()->json(['message'=>"Stok {$product->name} bertambah {$data['quantity']}.",'stock'=>$product->fresh()->stock]);
        return back()->with('success',"Stok {$product->name} bertambah {$data['quantity']}.");
    }

    public function updatePrice(Request $request,Product $product)
    {
        abort_unless($request->user()->isOwner(), 403);
        $this->authorizeOutlet($request,$product);$request->merge(['cost_price'=>preg_replace('/\D/','',(string)$request->cost_price),'selling_price'=>preg_replace('/\D/','',(string)$request->selling_price)]);$data=$request->validate(['cost_price'=>['required','integer','min:0'],'selling_price'=>['required','integer','gte:cost_price']],['selling_price.gte'=>'Harga jual tidak boleh lebih kecil dari modal.']);$product->update($data);return response()->json(['message'=>'Harga produk diperbarui.','cost_price'=>$product->cost_price,'selling_price'=>$product->selling_price]);
    }

    private function validated(Request $request): array
    {
        $isAccessory = $request->operator === 'AKSESORIS';
        $isBalance = $request->category === 'Saldo Provider';
        $request->merge([
            'cost_price' => preg_replace('/\D/', '', (string) $request->cost_price),
            'selling_price' => preg_replace('/\D/', '', (string) $request->selling_price),
            'stock' => preg_replace('/\D/', '', (string) $request->stock),
        ]);
        $data = $request->validate([
            'operator'=>['required',Rule::in(self::OPERATORS)], 'category'=>['required',Rule::in(self::CATEGORIES)],
            'name'=>['nullable','required_if:operator,AKSESORIS','string','max:255'],
            'quota_gb'=>['nullable',Rule::requiredIf(! $isAccessory && ! $isBalance),'numeric','min:1','max:30'],
            'validity_days'=>['nullable',Rule::requiredIf(! $isAccessory && ! $isBalance),'integer',Rule::in(self::VALIDITY_DAYS)], 'sku'=>['nullable','string','max:80'],
            'cost_price'=>['required','integer','min:0'], 'selling_price'=>['required','integer','gte:cost_price'],
            'stock'=>['required','integer','min:0','max:1000000000000'],
        ], ['selling_price.gte'=>'Harga jual tidak boleh lebih kecil dari modal.']);
        if ($isBalance) {
            $data = array_replace($data, ['name'=>$this->channelName($data['operator']), 'quota_gb'=>null,
                'validity_days'=>null, 'cost_price'=>0, 'selling_price'=>0]);
        }
        return $data;
    }

    private function productName(array $data): string
    {
        if ($data['category'] === 'Saldo Provider') return $this->channelName($data['operator']);
        if ($data['operator'] === 'AKSESORIS') return trim($data['name']);
        $quota = fmod((float) $data['quota_gb'], 1.0) === 0.0 ? (int) $data['quota_gb'] : $data['quota_gb'];
        return $quota.'GB · '.$data['validity_days'].'D';
    }

    private function ensureNotDuplicate(Request $request, array $data, ?int $exceptId = null): void
    {
        $query = Product::where('outlet_id', $request->user()->outlet_id)
            ->where('operator', $data['operator'])->where('category', $data['category'])->where('cost_price',$data['cost_price']);
        if ($data['category']==='Saldo Provider') $query->where('name',$this->channelName($data['operator']));
        elseif ($data['operator']==='AKSESORIS') $query->where('name',trim($data['name']));
        else $query->where('quota_gb',$data['quota_gb'])->where('validity_days',$data['validity_days']);
        if ($exceptId) $query->whereKeyNot($exceptId);
        if ($query->exists()) throw ValidationException::withMessages([
            'quota_gb' => 'Produk dengan detail dan harga modal yang sama sudah ada di outlet Anda.',
        ]);
    }

    private function formView(Product $product)
    {
        $quotas = [];
        for ($quota = 1; $quota <= 30; $quota += .5) $quotas[] = $quota;
        $existingPackages = Product::where('outlet_id', auth()->user()->outlet_id)
            ->get(['id','operator','category','name','quota_gb','validity_days','cost_price']);
        return view('products.form', ['product'=>$product,'operators'=>self::OPERATORS,
            'categories'=>self::CATEGORIES,'quotas'=>$quotas,'validityDays'=>self::VALIDITY_DAYS,
            'existingPackages'=>$existingPackages]);
    }

    private function authorizeOutlet(Request $request, Product $product): void
    {
        abort_unless($product->outlet_id === $request->user()->outlet_id, 404);
    }

    private function channelName(string $operator): string
    {
        return 'Saldo '.match ($operator) {
            'DIGIPOS' => 'DigiPOS', 'SIDIVA' => 'SIDIVA', 'ISIMPEL' => 'iSimpel', 'RITA' => 'RITA', 'MULTI' => 'MULTI',
            'DANA' => 'DANA', 'OVO' => 'OVO', 'GOPAY' => 'GoPay', 'SHOPEEPAY' => 'ShopeePay',
            'MAXIM' => 'Maxim', 'BRILINK' => 'BRILink', 'LINKAJA' => 'LinkAja',
            'TELKOMSEL', 'BYU' => 'DigiPOS',
            'XL', 'AXIS', 'SMARTFREN' => 'SIDIVA',
            'INDOSAT' => 'iSimpel',
            'TRI' => 'RITA',
            default => 'MULTI',
        };
    }

    private function displayChannelName(string $operator): string
    {
        return str_replace('Saldo ', '', $this->channelName($operator));
    }
}

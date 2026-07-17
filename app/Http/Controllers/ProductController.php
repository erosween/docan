<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private const OPERATORS = ['TELKOMSEL','BYU','INDOSAT','XL','TRI','SMARTFREN','AXIS','AKSESORIS'];
    private const CATEGORIES = ['Voucher Internet','Kartu Paket','Saldo Provider','Aksesoris HP'];
    private const VALIDITY_DAYS = [1,2,3,5,7,14,28,30];
    private const LOGOS = [
        'TELKOMSEL'=>'telkomsel.svg','BYU'=>'byu.svg','INDOSAT'=>'indosat.svg',
        'XL'=>'xl.svg','TRI'=>'tri.svg','SMARTFREN'=>'smartfren.svg',
        'AXIS'=>'axis.svg',
    ];

    public function index(Request $request)
    {
        if ($request->user()->isFrontliner() && ! $request->boolean('stock')) {
            return redirect()->route('products.index', ['stock' => 1]);
        }
        $query = Product::where('outlet_id', $request->user()->outlet_id);
        if ($request->filled('operator')) $query->where('operator', $request->operator);
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
        if ($request->filled('operator')) $detailStatsQuery->where('operator', $request->operator);
        $detailStats = $detailStatsQuery
            ->selectRaw("COUNT(*) as total, COALESCE(SUM(CASE WHEN category <> 'Saldo Provider' THEN stock ELSE 0 END),0) as stock, COALESCE(SUM(stock * cost_price),0) as value")->first();
        $stockRows = (clone $baseQuery)
            ->select('operator', 'category')
            ->selectRaw('COUNT(*) as product_count, COALESCE(SUM(stock),0) as stock')
            ->groupBy('operator', 'category')->get();
        $providerSummaries = collect(self::OPERATORS)
            ->reject(fn ($operator) => $operator === 'AKSESORIS')
            ->map(function ($operator) use ($stockRows) {
                $rows = $stockRows->where('operator', $operator);
                return [
                    'operator' => $operator,
                    'logo' => self::LOGOS[$operator] ?? null,
                    'products' => (int) $rows->sum('product_count'),
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
        return view('products.index', compact('products', 'productGroups', 'stats', 'detailStats', 'providerSummaries') + ['operators'=>self::OPERATORS]);
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
        $redirect = $request->boolean('variant')
            ? route('products.index', ['operator'=>$data['operator']])
            : route('products.index');
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
        return $quota.'GB · '.$data['validity_days'].' Hari';
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
            'TELKOMSEL', 'BYU' => 'DigiPOS',
            'XL', 'AXIS', 'SMARTFREN' => 'SIDIVA',
            'INDOSAT' => 'iSimpel',
            'TRI' => 'RITA',
            default => 'MULTI',
        };
    }
}

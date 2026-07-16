<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    private const OPERATORS = ['TELKOMSEL','BYU','INDOSAT','XL','TRI','SMARTFREN','AXIS','AKSESORIS'];
    private const CATEGORIES = ['Voucher Internet','Kartu Paket','Aksesoris HP'];
    private const VALIDITY_DAYS = [1,2,3,5,7,14,28,30];

    public function index(Request $request)
    {
        $query = Product::where('outlet_id', $request->user()->outlet_id);
        if ($request->filled('q')) $query->where('name', 'like', '%'.$request->q.'%');
        if ($request->filled('operator')) $query->where('operator', $request->operator);
        $products = $query->latest()->paginate(12)->withQueryString();
        $stats = Product::where('outlet_id', $request->user()->outlet_id)
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(stock),0) as stock, COALESCE(SUM(stock * cost_price),0) as value')->first();
        return view('products.index', ['products'=>$products,'stats'=>$stats,'operators'=>self::OPERATORS]);
    }

    public function create() { return $this->formView(new Product); }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $this->ensureNotDuplicate($request, $data);
        $data['name'] = $this->productName($data);
        Product::create([...$data, 'outlet_id'=>$request->user()->outlet_id, 'is_active'=>$request->boolean('is_active')]);
        return redirect()->route('products.index')->with('success', 'Produk berhasil ditambahkan.');
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
        $this->ensureNotDuplicate($request, $data, $product->id);
        $data['name'] = $this->productName($data);
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
        $data=$request->validate(['quantity'=>['required','integer','min:1','max:10000']]);$product->increment('stock',$data['quantity']);
        if($request->expectsJson())return response()->json(['message'=>"Stok {$product->name} bertambah {$data['quantity']}.",'stock'=>$product->fresh()->stock]);
        return back()->with('success',"Stok {$product->name} bertambah {$data['quantity']}.");
    }

    public function updatePrice(Request $request,Product $product)
    {
        $this->authorizeOutlet($request,$product);$request->merge(['cost_price'=>preg_replace('/\D/','',(string)$request->cost_price),'selling_price'=>preg_replace('/\D/','',(string)$request->selling_price)]);$data=$request->validate(['cost_price'=>['required','integer','min:0'],'selling_price'=>['required','integer','gte:cost_price']],['selling_price.gte'=>'Harga jual tidak boleh lebih kecil dari modal.']);$product->update($data);return response()->json(['message'=>'Harga produk diperbarui.','cost_price'=>$product->cost_price,'selling_price'=>$product->selling_price]);
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'cost_price' => preg_replace('/\D/', '', (string) $request->cost_price),
            'selling_price' => preg_replace('/\D/', '', (string) $request->selling_price),
        ]);
        return $request->validate([
            'operator'=>['required',Rule::in(self::OPERATORS)], 'category'=>['required',Rule::in(self::CATEGORIES)],
            'name'=>['nullable','required_if:operator,AKSESORIS','string','max:255'],
            'quota_gb'=>['nullable',Rule::requiredIf($request->operator!=='AKSESORIS'),'numeric','min:1','max:30'],
            'validity_days'=>['nullable',Rule::requiredIf($request->operator!=='AKSESORIS'),'integer',Rule::in(self::VALIDITY_DAYS)], 'sku'=>['nullable','string','max:80'],
            'cost_price'=>['required','integer','min:0'], 'selling_price'=>['required','integer','gte:cost_price'],
            'stock'=>['required','integer','min:0'],
        ], ['selling_price.gte'=>'Harga jual tidak boleh lebih kecil dari modal.']);
    }

    private function productName(array $data): string
    {
        if ($data['operator'] === 'AKSESORIS') return trim($data['name']);
        $quota = fmod((float) $data['quota_gb'], 1.0) === 0.0 ? (int) $data['quota_gb'] : $data['quota_gb'];
        return $quota.'GB · '.$data['validity_days'].' Hari';
    }

    private function ensureNotDuplicate(Request $request, array $data, ?int $exceptId = null): void
    {
        $query = Product::where('outlet_id', $request->user()->outlet_id)
            ->where('operator', $data['operator'])->where('category', $data['category'])->where('cost_price',$data['cost_price']);
        if ($data['operator']==='AKSESORIS') $query->where('name',trim($data['name']));
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
}

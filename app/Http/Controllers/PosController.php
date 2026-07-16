<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\Denomination;
use App\Models\ProductCardNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;

class PosController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role === 'super_admin') return redirect()->route('admin.dashboard');
        $providers = collect([
            ['id'=>'TELKOMSEL','name'=>'Telkomsel','logo'=>'telkomsel.svg','color'=>'#ed1b2f','soft'=>'#fff0f1'],
            ['id'=>'BYU','name'=>'by.U','logo'=>'byu.svg','color'=>'#15a9e5','soft'=>'#eaf8fe'],
            ['id'=>'INDOSAT','name'=>'Indosat','logo'=>'indosat.svg','color'=>'#f5b800','soft'=>'#fff8dc'],
            ['id'=>'XL','name'=>'XL','logo'=>'xl.svg','color'=>'#1947ba','soft'=>'#edf2ff'],
            ['id'=>'TRI','name'=>'Tri','logo'=>'tri.svg','color'=>'#16131d','soft'=>'#f1eff4'],
            ['id'=>'SMARTFREN','name'=>'Smartfren','logo'=>'smartfren.svg','color'=>'#ee168c','soft'=>'#fff0f8'],
            ['id'=>'AXIS','name'=>'Axis','logo'=>'axis.svg','color'=>'#6d2180','soft'=>'#f8effb'],
            ['id'=>'DANA','name'=>'DANA','logo'=>'dana.svg','color'=>'#108ee9','soft'=>'#edf7ff'],
            ['id'=>'OVO','name'=>'OVO','logo'=>'ovo.svg','color'=>'#4c2a86','soft'=>'#f4f0fb'],
            ['id'=>'GOPAY','name'=>'GoPay','logo'=>'gopay.svg','color'=>'#00aed6','soft'=>'#eafaff'],
            ['id'=>'SHOPEEPAY','name'=>'ShopeePay','logo'=>'shopeepay.svg','color'=>'#ee4d2d','soft'=>'#fff1ee'],
            ['id'=>'PLN','name'=>'Token PLN','logo'=>'pln.svg','color'=>'#f39c12','soft'=>'#fff7e8'],
            ['id'=>'AKSESORIS','name'=>'Aksesoris HP','logo'=>'accessories.svg','color'=>'#ec765f','soft'=>'#fff1ed'],
            ['id'=>'BRILINK','name'=>'BRILink','logo'=>'brilink.svg','color'=>'#165baa','soft'=>'#edf5ff'],
            ['id'=>'PPOB','name'=>'PPOB','logo'=>'ppob.svg','color'=>'#7667a7','soft'=>'#f3f0fb'],
        ]);

        $products = Product::where('outlet_id', $request->user()->outlet_id)
            ->where('is_active', true)->orderBy('selling_price')->get();
        $counts = $products->groupBy('operator')->map->count();
        $providers = $providers->map(fn ($provider) => [...$provider, 'count' => $counts[$provider['id']] ?? 0]);
        $frequentProducts = Product::query()
            ->join('transactions', 'transactions.product_id', '=', 'products.id')
            ->where('transactions.user_id', $request->user()->id)
            ->where('products.outlet_id', $request->user()->outlet_id)
            ->where('products.is_active', true)->where('products.stock', '>', 0)
            ->select('products.*')->selectRaw('COUNT(transactions.id) as sales_count')
            ->groupBy('products.id')->orderByDesc('sales_count')->limit(4)->get();
        $daily = Transaction::where('user_id', $request->user()->id)
            ->whereBetween('created_at', [today(), today()->endOfDay()])
            ->selectRaw('COALESCE(SUM(price),0) as omset, COALESCE(SUM(profit),0) as profit')->first();
        $omset = (int) $daily->omset;
        $profit = (int) $daily->profit;
        $denominations = Denomination::where('is_active', true)->orderBy('nominal')->get();

        return view('pos.index', compact('providers', 'products', 'frequentProducts', 'denominations', 'omset', 'profit'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_number' => ['nullable','string','max:25'],
            'product_id' => ['nullable','integer'],
            'provider' => [Rule::excludeIf($request->filled('product_id')),'required_without:product_id','nullable','string','max:40'],
            'product_type' => [Rule::excludeIf($request->filled('product_id')),'required_without:product_id','nullable','string','max:60'],
            'nominal' => [Rule::excludeIf($request->filled('product_id')),'required_without:product_id','nullable','integer','min:1000','max:10000000'],
            'quantity'=>['nullable','integer','min:1','max:100'], 'card_numbers'=>['nullable','string','max:10000'],
            'request_token'=>['nullable','uuid'],
        ]);

        $soldCard=null;
        try { DB::transaction(function () use ($data, $request, &$soldCard) {
            if (empty($data['product_id'])) {
                $this->ensureDirectIdentifier($data['customer_number'] ?? null, $data['provider']);
                $this->ensureCustomerMatchesProvider($data['customer_number'] ?? null, $data['provider']);
                Transaction::create(['request_token'=>$data['request_token']??null,'user_id'=>$request->user()->id,'customer_number'=>($data['customer_number'] ?? null) ?: '-',
                    'provider'=>$data['provider'],'product_type'=>$data['product_type'],'nominal'=>$data['nominal'],
                    'price'=>$data['nominal'],'cost_price'=>$data['nominal'],'profit'=>0]);
                return;
            }
            $product = Product::where('outlet_id', $request->user()->outlet_id)
                ->lockForUpdate()->findOrFail($data['product_id']);
            if($product->category!=='Kartu Paket')$this->ensureCustomerMatchesProvider($data['customer_number'] ?? null, $product->operator);
            $numbers=$product->category==='Kartu Paket'?$this->normalizeCardNumbers($data['card_numbers']??'',$product->operator):[];$quantity=$product->category==='Kartu Paket'?count($numbers):1;
            if (! $product->is_active || $product->stock < $quantity) {
                throw ValidationException::withMessages(['product_id' => 'Stok produk sudah habis.']);
            }
            if($numbers){$existing=ProductCardNumber::whereIn('card_number',$numbers)->exists();if($existing)throw ValidationException::withMessages(['card_numbers'=>'Salah satu nomor kartu sudah pernah dijual.']);}
            $product->decrement('stock',$quantity);
            $transaction=Transaction::create([
                'request_token' => $data['request_token'] ?? null,
                'user_id' => $request->user()->id,
                'product_id' => $product->id,
                'customer_number' => ($data['customer_number'] ?? null) ?: '-',
                'provider' => $product->operator,
                'product_type' => $product->category,
                'quantity'=>$quantity,'card_numbers'=>$numbers?:null,
                'nominal' => $product->selling_price,
                'price' => $product->selling_price*$quantity,
                'cost_price' => $product->cost_price*$quantity,
                'profit' => ($product->selling_price-$product->cost_price)*$quantity,
            ]);
            foreach($numbers as $number)ProductCardNumber::create(['product_id'=>$product->id,'card_number'=>$number,'transaction_id'=>$transaction->id,'sold_at'=>now()]);if($numbers)$soldCard=implode(', ',$numbers);
        }); } catch (QueryException $exception) {
            if (($data['request_token'] ?? null) && Transaction::where('request_token',$data['request_token'])->where('user_id',$request->user()->id)->exists()) return back()->with('success','Transaksi sudah diproses sebelumnya.');
            throw $exception;
        }

        $message = empty($data['product_id'])
            ? 'Pembayaran berhasil dicatat.'
            : ($soldCard ? 'Nomor Kartu Paket: '.$soldCard : 'Stok otomatis berkurang 1.');
        Cache::forget('reports:outlet:'.$request->user()->outlet_id.':summary');
        return back()->with('success', $message);
    }

    private function normalizeCardNumbers(string $input,string $provider):array
    {
        $numbers=[];foreach(preg_split('/[\r\n,;]+/',trim($input)) as $line){$raw=trim($line);if($raw==='')continue;if(!preg_match('/^[+0-9\s-]+$/',$raw))throw ValidationException::withMessages(['card_numbers'=>'Nomor kartu hanya boleh berisi angka.']);$number=preg_replace('/\D/','',$raw);if(str_starts_with($number,'62'))$number='0'.substr($number,2);elseif(str_starts_with($number,'8'))$number='0'.$number;if(strlen($number)<8||strlen($number)>22)throw ValidationException::withMessages(['card_numbers'=>"Nomor {$raw} tidak valid."]);try{$this->ensureCustomerMatchesProvider($number,$provider);}catch(ValidationException){throw ValidationException::withMessages(['card_numbers'=>"Nomor {$number} bukan nomor {$provider}."]);}$numbers[]=$number;}if(!$numbers)throw ValidationException::withMessages(['card_numbers'=>'Masukkan minimal satu nomor Kartu Paket yang dijual.']);if(count($numbers)!==count(array_unique($numbers)))throw ValidationException::withMessages(['card_numbers'=>'Ada nomor kartu yang ditulis lebih dari sekali.']);return $numbers;
    }

    private function ensureCustomerMatchesProvider(?string $number, string $provider): void
    {
        if (! $number || $number === '-') return;
        $prefixes = [
            'TELKOMSEL'=>['0811','0812','0813','0821','0822','0823','0851','0852','0853'],
            'BYU'=>['0851'], 'INDOSAT'=>['0814','0815','0816','0855','0856','0857','0858'],
            'XL'=>['0817','0818','0819','0859','0877','0878'], 'AXIS'=>['0831','0832','0833','0838'],
            'TRI'=>['0895','0896','0897','0898','0899'],
            'SMARTFREN'=>['0881','0882','0883','0884','0885','0886','0887','0888','0889'],
        ];
        if (! isset($prefixes[$provider])) return;
        $digits = preg_replace('/\D/', '', $number);
        if (str_starts_with($digits, '62')) $digits = '0'.substr($digits, 2);
        elseif (str_starts_with($digits, '8')) $digits = '0'.$digits;
        if (! collect($prefixes[$provider])->contains(fn ($prefix) => str_starts_with($digits, $prefix))) {
            throw ValidationException::withMessages(['customer_number'=>"Nomor pelanggan bukan nomor {$provider}."]);
        }
    }

    private function ensureDirectIdentifier(?string $identifier, string $provider): void
    {
        if (! in_array($provider, ['DANA','OVO','GOPAY','SHOPEEPAY','PPOB','BRILINK'], true)) return;
        if (strlen(trim((string) $identifier)) >= 4) return;

        $message = match ($provider) {
            'PPOB' => 'Masukkan ID pelanggan PPOB.',
            'BRILINK' => 'Masukkan nomor VA atau rekening tujuan.',
            default => 'Masukkan nomor akun e-wallet pelanggan.',
        };
        throw ValidationException::withMessages(['customer_number' => $message]);
    }
}

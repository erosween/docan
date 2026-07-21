<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\Denomination;
use App\Models\ProductCardNumber;
use App\Models\ProductStockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\Database\QueryException;

class PosController extends Controller
{
    private const DIRECT_PROVIDERS = ['TELKOMSEL','BYU','INDOSAT','XL','TRI','SMARTFREN','AXIS','LINKAJA','DANA','OVO','GOPAY','SHOPEEPAY','MAXIM','PPOB','BRILINK','DIGIPOS','SIDIVA','ISIMPEL','RITA','MULTI','PLN'];
    private const E_WALLET_PROVIDERS = ['LINKAJA','DANA','OVO','GOPAY','SHOPEEPAY','MAXIM','BRILINK'];
    private const DIRECT_CATEGORIES = ['Pulsa','Paket Tembak','PPOB','Digital','Pulsa Reguler','Pulsa Data','Saldo E-Wallet','Token PLN','Transfer','Tarik Tunai','Setor Tunai','BPJS Kesehatan','PDAM','Internet & TV','Pascabayar','Pajak & PBB','Listrik PLN Pascabayar','Telepon & Telkom/IndiHome','TV Berlangganan','Cicilan/Multifinance','Pulsa Elektrik','Paket Data/Internet','Token Listrik','Voucher Game'];
    private const PPOB_SERVICES = ['PPOB','Listrik PLN Pascabayar','PDAM','BPJS Kesehatan','Telepon & Telkom/IndiHome','TV Berlangganan','Cicilan/Multifinance','Pulsa Elektrik','Paket Data/Internet','Token Listrik','Voucher Game'];
    public function index(Request $request)
    {
        if ($request->user()->role === 'super_admin') return redirect()->route('admin.dashboard');
        $providers = collect([
            ['id'=>'TELKOMSEL','name'=>'Telkomsel','logo'=>'telkomsel.svg','color'=>'#ed1b2f','soft'=>'#fff0f1'],
            ['id'=>'BYU','name'=>'by.U','logo'=>'byu.svg','color'=>'#15a9e5','soft'=>'#eaf8fe'],
            ['id'=>'INDOSAT','name'=>'Indosat','logo'=>'indosat.svg','color'=>'#f5b800','soft'=>'#fff8dc'],
            ['id'=>'XL','name'=>'XL','logo'=>'xl.svg','color'=>'#1947ba','soft'=>'#edf2ff'],
            ['id'=>'TRI','name'=>'Tri','logo'=>'tri.svg','color'=>'#16131d','soft'=>'#f1eff4'],
            ['id'=>'SMARTFREN','name'=>'Smartfren','logo'=>'smartfren-official.svg','color'=>'#ee168c','soft'=>'#fff0f8'],
            ['id'=>'AXIS','name'=>'Axis','logo'=>'axis.svg','color'=>'#6d2180','soft'=>'#f8effb'],
            ['id'=>'DANA','name'=>'DANA','logo'=>'dana.webp','color'=>'#108ee9','soft'=>'#edf7ff'],
            ['id'=>'OVO','name'=>'OVO','logo'=>'ovo.webp','color'=>'#4c2a86','soft'=>'#f4f0fb'],
            ['id'=>'GOPAY','name'=>'GoPay','logo'=>'gopay.webp','color'=>'#00aed6','soft'=>'#eafaff'],
            ['id'=>'SHOPEEPAY','name'=>'ShopeePay','logo'=>'shopeepay.webp','color'=>'#ee4d2d','soft'=>'#fff1ee'],
            ['id'=>'MAXIM','name'=>'Maxim','logo'=>'maxim.svg','color'=>'#f1c900','soft'=>'#fff9d8'],
            ['id'=>'PLN','name'=>'Token PLN','logo'=>'pln.svg','color'=>'#f39c12','soft'=>'#fff7e8'],
            ['id'=>'AKSESORIS','name'=>'Aksesoris HP','logo'=>'accessories.svg','color'=>'#ec765f','soft'=>'#fff1ed'],
            ['id'=>'BRILINK','name'=>'BRILink','logo'=>'brilink.svg','color'=>'#165baa','soft'=>'#edf5ff'],
            ['id'=>'PPOB','name'=>'PPOB','logo'=>'ppob.svg','color'=>'#7667a7','soft'=>'#f3f0fb'],
            ['id'=>'LINKAJA','name'=>'LinkAja','logo'=>'linkaja.webp','color'=>'#e1252a','soft'=>'#fff0f0'],
            ['id'=>'DIGIPOS','name'=>'DigiPOS','logo'=>'telkomsel.svg','color'=>'#ed1b2f','soft'=>'#fff0f1'],
            ['id'=>'SIDIVA','name'=>'SIDIVA · XL/Axis/Smartfren','logo'=>'xl.svg','color'=>'#1947ba','soft'=>'#edf2ff'],
            ['id'=>'ISIMPEL','name'=>'iSimpel · Indosat','logo'=>'indosat.svg','color'=>'#f5b800','soft'=>'#fff8dc'],
            ['id'=>'RITA','name'=>'RITA · Tri','logo'=>'tri.svg','color'=>'#16131d','soft'=>'#f1eff4'],
            ['id'=>'MULTI','name'=>'MULTI','logo'=>'multi.svg','color'=>'#7443a8','soft'=>'#f5edfc'],
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
            'cart_items' => ['nullable','string','max:50000'],
            'provider' => [Rule::excludeIf($request->filled('product_id') || $request->filled('cart_items')),Rule::requiredIf(! $request->filled('product_id') && ! $request->filled('cart_items')),'nullable','string','max:40',Rule::in(self::DIRECT_PROVIDERS)],
            'product_type' => [Rule::excludeIf($request->filled('product_id') || $request->filled('cart_items')),Rule::requiredIf(! $request->filled('product_id') && ! $request->filled('cart_items')),'nullable','string','max:60',Rule::in(self::DIRECT_CATEGORIES)],
            'nominal' => [Rule::excludeIf($request->filled('product_id') || $request->filled('cart_items')),Rule::requiredIf(! $request->filled('product_id') && ! $request->filled('cart_items')),'nullable','integer','min:1000','max:10000000'],
            'admin_fee' => ['nullable','integer','min:1000','max:10000'],
            'balance_product_id' => ['nullable','integer'],
            'quantity'=>['nullable','integer','min:1','max:100'], 'card_numbers'=>['nullable','string','max:10000'],
            'request_token'=>['nullable','uuid'],
        ]);

        $cart = [];
        if (! empty($data['cart_items'])) {
            $cart = json_decode($data['cart_items'], true);
            if (! is_array($cart) || count($cart) < 1 || count($cart) > 50) {
                throw ValidationException::withMessages(['cart_items'=>'Keranjang tidak valid atau terlalu banyak.']);
            }
            foreach ($cart as $index => $item) {
                if (! is_array($item) || ! filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT)
                    || ! filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT)
                    || (int) $item['quantity'] < 1 || (int) $item['quantity'] > 100000) {
                    throw ValidationException::withMessages(['cart_items'=>'Item keranjang ke-'.($index + 1).' tidak valid.']);
                }
            }
            if (count(array_unique(array_column($cart, 'product_id'))) !== count($cart)) {
                throw ValidationException::withMessages(['cart_items'=>'Produk yang sama tercatat lebih dari sekali.']);
            }
        }

        $soldCard=null;
        try { DB::transaction(function () use ($data, $request, $cart, &$soldCard) {
            if ($cart) {
                $token = $data['request_token'] ?? null;
                $soldCards = [];
                foreach ($cart as $index => $item) {
                    $product = Product::where('outlet_id', $request->user()->outlet_id)
                        ->lockForUpdate()->find($item['product_id']);
                    if (! $product || ! $product->is_active) {
                        throw ValidationException::withMessages(['cart_items'=>'Salah satu produk tidak tersedia lagi.']);
                    }
                    if ($product->category !== 'Kartu Paket') {
                        $this->ensureCustomerMatchesProvider($data['customer_number'] ?? null, $product->operator);
                    }
                    $numbers = $product->category === 'Kartu Paket'
                        ? $this->normalizeCardNumbers(implode("\n", (array) ($item['card_numbers'] ?? [])), $product->operator)
                        : [];
                    $quantity = $product->category === 'Kartu Paket' ? count($numbers) : (int) $item['quantity'];
                    if ($quantity !== (int) $item['quantity'] || $product->stock < $quantity) {
                        throw ValidationException::withMessages(['cart_items'=>"Stok {$product->name} tidak cukup atau jumlah kartu tidak sesuai."]);
                    }
                    if ($numbers && ProductCardNumber::whereIn('card_number', $numbers)->exists()) {
                        throw ValidationException::withMessages(['cart_items'=>'Salah satu nomor kartu sudah pernah dijual.']);
                    }
                    $before = (int) $product->stock;
                    $product->decrement('stock', $quantity);
                    $transaction = Transaction::create([
                        'request_token'=>$index === 0 ? $token : null, 'user_id'=>$request->user()->id,
                        'product_id'=>$product->id, 'customer_number'=>($data['customer_number'] ?? null) ?: '-',
                        'provider'=>$product->operator, 'product_type'=>$product->category, 'quantity'=>$quantity,
                        'card_numbers'=>$numbers ?: null, 'nominal'=>$product->selling_price,
                        'price'=>$product->selling_price * $quantity, 'cost_price'=>$product->cost_price * $quantity,
                        'profit'=>($product->selling_price - $product->cost_price) * $quantity,
                    ]);
                    $this->recordSaleMovement($product, $request, $transaction, -$quantity, $before, $before - $quantity);
                    foreach ($numbers as $number) {
                        ProductCardNumber::create(['product_id'=>$product->id,'card_number'=>$number,'transaction_id'=>$transaction->id,'sold_at'=>now()]);
                        $soldCards[] = $number;
                    }
                }
                $soldCard = $soldCards ? implode(', ', $soldCards) : null;
                return;
            }
            if (empty($data['product_id'])) {
                $this->ensureDirectIdentifier($data['customer_number'] ?? null, $data['provider']);
                if (! in_array($data['product_type'], self::PPOB_SERVICES, true)) {
                    $this->ensureCustomerMatchesProvider($data['customer_number'] ?? null, $data['provider']);
                }
                $adminFee = (in_array($data['provider'], self::E_WALLET_PROVIDERS, true)
                    || in_array($data['provider'], ['DIGIPOS','SIDIVA','ISIMPEL','RITA','MULTI'], true))
                    ? (int) ($data['admin_fee'] ?? 1000)
                    : 0;
                $balanceProduct = null;
                if (in_array($data['provider'], self::E_WALLET_PROVIDERS, true)) {
                    if (empty($data['balance_product_id'])) {
                        throw ValidationException::withMessages(['balance_product_id'=>'Pilih akun saldo yang akan dipotong.']);
                    }
                    $balanceProduct = Product::where('outlet_id', $request->user()->outlet_id)
                        ->where('operator', $data['provider'])->where('category', 'Saldo Provider')
                        ->lockForUpdate()->find($data['balance_product_id']);
                    if (! $balanceProduct) {
                        throw ValidationException::withMessages(['balance_product_id'=>'Akun saldo tidak ditemukan atau tidak sesuai layanan.']);
                    }
                    if ($balanceProduct->stock < $data['nominal']) {
                        throw ValidationException::withMessages(['balance_product_id'=>'Saldo akun tidak mencukupi untuk nominal transaksi ini.']);
                    }
                }
                $transaction = Transaction::create(['request_token'=>$data['request_token']??null,'user_id'=>$request->user()->id,'customer_number'=>($data['customer_number'] ?? null) ?: '-',
                    'provider'=>$data['provider'],'product_type'=>$data['product_type'],'nominal'=>$data['nominal'],
                    'admin_fee'=>$adminFee,'price'=>$data['nominal']+$adminFee,'cost_price'=>$data['nominal'],'profit'=>$adminFee]);
                if ($balanceProduct) {
                    $before = (int) $balanceProduct->stock;
                    $after = $before - (int) $data['nominal'];
                    $balanceProduct->update(['stock'=>$after]);
                    $this->recordSaleMovement($balanceProduct, $request, $transaction, -(int) $data['nominal'], $before, $after);
                }
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
            $beforeStock = (int) $product->stock;
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
            $this->recordSaleMovement($product, $request, $transaction, -$quantity, $beforeStock, $beforeStock - $quantity);
            foreach($numbers as $number)ProductCardNumber::create(['product_id'=>$product->id,'card_number'=>$number,'transaction_id'=>$transaction->id,'sold_at'=>now()]);if($numbers)$soldCard=implode(', ',$numbers);
        }); } catch (QueryException $exception) {
            if (($data['request_token'] ?? null) && Transaction::where('request_token',$data['request_token'])->where('user_id',$request->user()->id)->exists()) return back()->with('success','Transaksi sudah diproses sebelumnya.');
            throw $exception;
        }

        $message = $cart
            ? count($cart).' jenis produk berhasil dijual dalam satu pesanan.'
            : (empty($data['product_id'])
            ? 'Pembayaran berhasil dicatat.'
            : ($soldCard ? 'Nomor Kartu Paket: '.$soldCard : 'Stok otomatis berkurang 1.'));
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
        $provider = match ($provider) {
            'DIGIPOS' => 'TELKOMSEL',
            'ISIMPEL' => 'INDOSAT',
            'RITA' => 'TRI',
            default => $provider,
        };
        $prefixes = [
            'TELKOMSEL'=>['0811','0812','0813','0821','0822','0823','0851','0852','0853'],
            'BYU'=>['0851'], 'INDOSAT'=>['0814','0815','0816','0855','0856','0857','0858'],
            'XL'=>['0817','0818','0819','0859','0877','0878'], 'AXIS'=>['0831','0832','0833','0838'],
            'TRI'=>['0895','0896','0897','0898','0899'],
            'SMARTFREN'=>['0881','0882','0883','0884','0885','0886','0887','0888','0889'],
        ];
        if ($provider === 'MULTI') return;
        $digits = preg_replace('/\D/', '', $number);
        if (str_starts_with($digits, '62')) $digits = '0'.substr($digits, 2);
        elseif (str_starts_with($digits, '8')) $digits = '0'.$digits;
        $allowedPrefixes = $provider === 'SIDIVA'
            ? [...$prefixes['XL'], ...$prefixes['AXIS'], ...$prefixes['SMARTFREN']]
            : ($prefixes[$provider] ?? []);
        if ($allowedPrefixes && ! collect($allowedPrefixes)->contains(fn ($prefix) => str_starts_with($digits, $prefix))) {
            throw ValidationException::withMessages(['customer_number'=>"Nomor pelanggan bukan nomor {$provider}."]);
        }
    }

    private function ensureDirectIdentifier(?string $identifier, string $provider): void
    {
        if (! in_array($provider, ['LINKAJA','DANA','OVO','GOPAY','SHOPEEPAY','MAXIM','PPOB','BRILINK','DIGIPOS','SIDIVA','ISIMPEL','RITA','MULTI'], true)) return;
        if (strlen(trim((string) $identifier)) >= 4) return;

        $message = match ($provider) {
            'PPOB' => 'Masukkan ID pelanggan PPOB.',
            'BRILINK' => 'Masukkan nomor VA atau rekening tujuan.',
            'DIGIPOS', 'SIDIVA', 'ISIMPEL', 'RITA', 'MULTI' => 'Masukkan nomor pelanggan tujuan.',
            default => 'Masukkan nomor akun e-wallet pelanggan.',
        };
        throw ValidationException::withMessages(['customer_number' => $message]);
    }

    private function recordSaleMovement(Product $product, Request $request, Transaction $transaction, int $quantity, int $before, int $after): void
    {
        ProductStockMovement::create([
            'outlet_id'=>$product->outlet_id, 'product_id'=>$product->id,
            'user_id'=>$request->user()->id, 'transaction_id'=>$transaction->id,
            'type'=>'sale', 'quantity'=>$quantity, 'stock_before'=>$before, 'stock_after'=>$after,
            'product_name'=>$product->name, 'operator'=>$product->operator,
            'category'=>$product->category, 'note'=>'Penjualan kasir',
        ]);
    }
}

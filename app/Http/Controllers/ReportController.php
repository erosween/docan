<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Product;
use App\Models\BusinessEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use Illuminate\Support\Str;

class ReportController extends Controller
{
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

        return view('reports.index', [
            ...$summary,
            'monthCount'=>$summary['count'],'monthTurnover'=>$summary['turnover'],'monthProfit'=>$summary['profit'],
            'period'=>$period,'periodKey'=>$periodKey,'weeks'=>$weekly,'topProducts'=>$topProducts,
            'recent'=>(clone $base)->with('product')->latest()->limit(10)->get(),
        ]);
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

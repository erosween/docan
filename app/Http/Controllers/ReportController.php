<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
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

        $summary = Cache::remember("reports:outlet:{$outletId}:summary", 20, function () use ($outletId) {
            $base = Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outletId));
            $today = (clone $base)->whereBetween('created_at', [today(), today()->endOfDay()])
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(price),0) as turnover, COALESCE(SUM(profit),0) as profit')->first();
            $month = (clone $base)->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(price),0) as turnover, COALESCE(SUM(profit),0) as profit')->first();
            $from = today()->subDays(6)->startOfDay();
            $daily = (clone $base)->whereBetween('created_at', [$from, today()->endOfDay()])
                ->selectRaw('DATE(created_at) as sale_date, COUNT(*) as count, COALESCE(SUM(price),0) as turnover')
                ->groupByRaw('DATE(created_at)')->get()->keyBy('sale_date');
            $days = collect(range(6, 0))->map(function ($offset) use ($daily) {
                $date = today()->subDays($offset); $row = $daily->get($date->format('Y-m-d'));
                return ['date'=>$date->format('Y-m-d'),'label'=>$date->translatedFormat('D'),'omset'=>(int)($row->turnover??0),'count'=>(int)($row->count??0)];
            });
            return [
                'today'=>['count'=>(int)$today->count,'turnover'=>(int)$today->turnover,'profit'=>(int)$today->profit],
                'month'=>['count'=>(int)$month->count,'turnover'=>(int)$month->turnover,'profit'=>(int)$month->profit],
                'days'=>$days->values()->all(),
            ];
        });

        $base = Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outletId));
        $topProducts = (clone $base)->whereNotNull('product_id')->with('product')
            ->selectRaw('product_id, COALESCE(SUM(quantity),0) as sold, COUNT(*) as transaction_count, SUM(price) as revenue')
            ->groupBy('product_id')->orderByDesc('sold')->limit(5)->get();

        return view('reports.index', [
            'todayCount'=>$summary['today']['count'],'todayTurnover'=>$summary['today']['turnover'],'todayProfit'=>$summary['today']['profit'],
            'monthCount'=>$summary['month']['count'],'monthTurnover'=>$summary['month']['turnover'],'monthProfit'=>$summary['month']['profit'],
            'days'=>collect($summary['days']),'topProducts'=>$topProducts,'recent'=>(clone $base)->with('product')->latest()->limit(10)->get(),
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

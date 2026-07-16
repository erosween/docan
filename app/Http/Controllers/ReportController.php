<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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
            return compact('today', 'month', 'days');
        });

        $base = Transaction::whereHas('user', fn ($query) => $query->where('outlet_id', $outletId));
        $topProducts = (clone $base)->whereNotNull('product_id')->with('product')
            ->selectRaw('product_id, COALESCE(SUM(quantity),0) as sold, COUNT(*) as transaction_count, SUM(price) as revenue')
            ->groupBy('product_id')->orderByDesc('sold')->limit(5)->get();

        return view('reports.index', [
            'todayCount'=>(int)$summary['today']->count,'todayTurnover'=>(int)$summary['today']->turnover,'todayProfit'=>(int)$summary['today']->profit,
            'monthCount'=>(int)$summary['month']->count,'monthTurnover'=>(int)$summary['month']->turnover,'monthProfit'=>(int)$summary['month']->profit,
            'days'=>$summary['days'],'topProducts'=>$topProducts,'recent'=>(clone $base)->with('product')->latest()->limit(10)->get(),
        ]);
    }

    public function settings(Request $request) { abort_if($request->user()->role === 'super_admin', 403); return view('settings.index'); }

    public function updatePassword(Request $request)
    {
        abort_if($request->user()->role === 'super_admin', 403);
        $data=$request->validate(['current_password'=>['required','current_password'],'password'=>['required','string','min:8','confirmed']],['current_password.current_password'=>'Password saat ini tidak sesuai.','password.confirmed'=>'Konfirmasi password baru tidak sama.']);
        $request->user()->update(['password'=>$data['password']]);
        return back()->with('success','Password berhasil diubah. Gunakan password baru saat login berikutnya.');
    }
}

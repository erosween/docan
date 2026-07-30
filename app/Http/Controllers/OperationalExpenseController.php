<?php

namespace App\Http\Controllers;

use App\Models\BusinessCategory;
use App\Models\BusinessEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OperationalExpenseController extends Controller
{
    private const DEFAULT_CATEGORIES = [
        'Bensin',
        'Uang Makan',
        'Listrik',
        'Biaya Sewa',
        'Ongkos Kirim',
        'Biaya Admin',
    ];

    public function index(Request $request)
    {
        $outletId = $request->user()->outlet_id;
        $this->ensureDefaultCategories($outletId);
        $month = $this->month($request);
        $categories = BusinessCategory::where('outlet_id', $outletId)
            ->where('kind', 'operational-expense')
            ->orderBy('name')
            ->get();
        $expenses = BusinessEntry::with('category')
            ->where('outlet_id', $outletId)
            ->where('type', 'operational-expense')
            ->whereBetween('entry_date', [$month->startOfMonth(), $month->endOfMonth()])
            ->latest('entry_date')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
        $categoryTotals = BusinessEntry::query()
            ->where('business_entries.outlet_id', $outletId)
            ->where('business_entries.type', 'operational-expense')
            ->whereBetween('business_entries.entry_date', [$month->startOfMonth(), $month->endOfMonth()])
            ->join('business_categories', 'business_categories.id', '=', 'business_entries.category_id')
            ->selectRaw('business_categories.name, COALESCE(SUM(business_entries.amount),0) as total')
            ->groupBy('business_categories.id', 'business_categories.name')
            ->orderByDesc('total')
            ->get();
        $total = (int) BusinessEntry::where('outlet_id', $outletId)
            ->where('type', 'operational-expense')
            ->whereBetween('entry_date', [$month->startOfMonth(), $month->endOfMonth()])
            ->sum('amount');

        return view('operational-expenses.index', [
            'categories' => $categories,
            'expenses' => $expenses,
            'categoryTotals' => $categoryTotals,
            'month' => $month,
            'monthKey' => $month->format('Y-m'),
            'total' => $total,
        ]);
    }

    public function store(Request $request)
    {
        $outletId = $request->user()->outlet_id;
        $request->merge(['amount' => preg_replace('/\D/', '', (string) $request->amount)]);
        $data = $request->validate([
            'category_id' => [
                'required',
                Rule::exists('business_categories', 'id')->where(fn ($query) => $query
                    ->where('outlet_id', $outletId)
                    ->where('kind', 'operational-expense')),
            ],
            'description' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        BusinessEntry::create([
            ...$data,
            'outlet_id' => $outletId,
            'user_id' => $request->user()->id,
            'type' => 'operational-expense',
            'status' => 'completed',
        ]);
        $this->forgetSummary($outletId, $data['entry_date']);

        return back()->with('success', 'Biaya operasional berhasil dicatat.');
    }

    public function update(Request $request, BusinessEntry $expense)
    {
        $this->authorizeExpense($request, $expense);
        $request->merge(['amount' => preg_replace('/\D/', '', (string) $request->amount)]);
        $data = $request->validate([
            'category_id' => [
                'required',
                Rule::exists('business_categories', 'id')->where(fn ($query) => $query
                    ->where('outlet_id', $request->user()->outlet_id)
                    ->where('kind', 'operational-expense')),
            ],
            'description' => ['required', 'string', 'max:180'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $oldDate = $expense->entry_date->toDateString();
        $expense->update($data);
        $this->forgetSummary($expense->outlet_id, $oldDate);
        $this->forgetSummary($expense->outlet_id, $data['entry_date']);

        return back()->with('success', 'Biaya operasional berhasil diperbarui.');
    }

    public function destroy(Request $request, BusinessEntry $expense)
    {
        $this->authorizeExpense($request, $expense);
        $date = $expense->entry_date->toDateString();
        $outletId = $expense->outlet_id;
        $expense->delete();
        $this->forgetSummary($outletId, $date);

        return back()->with('success', 'Biaya operasional berhasil dihapus.');
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        BusinessCategory::firstOrCreate([
            'outlet_id' => $request->user()->outlet_id,
            'kind' => 'operational-expense',
            'name' => trim($data['name']),
        ]);

        return back()->with('success', 'Kategori biaya berhasil ditambahkan.');
    }

    private function ensureDefaultCategories(int $outletId): void
    {
        DB::transaction(function () use ($outletId) {
            foreach (self::DEFAULT_CATEGORIES as $name) {
                BusinessCategory::firstOrCreate([
                    'outlet_id' => $outletId,
                    'kind' => 'operational-expense',
                    'name' => $name,
                ]);
            }
        });
    }

    private function authorizeExpense(Request $request, BusinessEntry $expense): void
    {
        abort_unless(
            $expense->outlet_id === $request->user()->outlet_id
            && $expense->type === 'operational-expense',
            404
        );
    }

    private function month(Request $request): CarbonImmutable
    {
        try {
            return $request->filled('month')
                ? CarbonImmutable::createFromFormat('!Y-m', $request->string('month')->toString())
                : CarbonImmutable::now()->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    private function forgetSummary(int $outletId, string $date): void
    {
        Cache::forget("reports:outlet:{$outletId}:".CarbonImmutable::parse($date)->format('Y-m').':summary');
    }
}

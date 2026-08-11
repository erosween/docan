<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SalesForceController extends Controller
{
    public function dashboard(Request $request)
    {
        $this->guard($request);
        $outlets = Outlet::with(['users' => fn ($query) => $query->whereIn('role', ['owner', 'frontliner'])])
            ->where('sf_user_id', $request->user()->id)
            ->withCount('products')
            ->withCount('transactions')
            ->withSum('transactions', 'quantity')
            ->withSum('transactions', 'price')
            ->withMax('transactions', 'created_at')
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END")
            ->latest()
            ->get();

        return view('sf.dashboard', compact('outlets'));
    }

    public function updateStatus(Request $request, Outlet $outlet)
    {
        $this->guard($request);
        abort_unless($outlet->sf_user_id === $request->user()->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'inactive'])]]);
        $outlet->update([
            'status' => $data['status'],
            'approved_at' => $data['status'] === 'active' ? ($outlet->approved_at ?? now()) : $outlet->approved_at,
        ]);

        return back()->with('success', $data['status'] === 'active'
            ? "Outlet {$outlet->name} sudah aktif."
            : "Outlet {$outlet->name} dinonaktifkan.");
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->role === 'sf', 403);
    }
}

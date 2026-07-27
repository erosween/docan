@extends('layouts.app')
@section('title', 'Laporan — Docan')
@section('body-class', 'pos-body')
@push('styles')
    <link rel="stylesheet" href="/vendor/flatpickr/flatpickr.min.css?v=4.6.13">
@endpush
@push('vendor-scripts')
    <script src="/vendor/flatpickr/flatpickr.min.js?v=4.6.13" defer></script>
    <script src="/vendor/flatpickr/id.js?v=4.6.13" defer></script>
@endpush
@section('content')
    <div class="app-shell report-page">
        @if(session('success') || $errors->any())
            <div class="report-popup {{ $errors->any() ? 'error' : 'success' }}" role="alert" data-report-popup>
                <span>{{ $errors->any() ? '!' : '✓' }}</span>
                <div><b>{{ $errors->any() ? 'Transaksi tidak dapat diproses' : 'Berhasil' }}</b>
                    <small>{{ $errors->first() ?: session('success') }}</small></div>
                <button type="button" aria-label="Tutup notifikasi" data-close-report-popup>×</button>
            </div>
        @endif
        <header class="topbar">
            <div class="brand"><span class="brand-mark">D</span>
                <div><b>Laporan Outlet</b><small>{{ auth()->user()->outlet?->name }}</small></div>
            </div>
            <div class="report-period">{{ $period->translatedFormat('F Y') }}</div>
        </header>
        <main class="report-main">
            <form class="report-filter" method="GET"><label for="report-month">Periode laporan</label>
                <div><input id="report-month" type="month" name="month" value="{{ $periodKey }}"><button
                        type="submit">Tampilkan</button></div>
            </form>
            <div class="report-hero"><span class="eyebrow">OMSET BULAN INI</span>
                <h1>Rp {{ number_format($monthTurnover, 0, ',', '.') }}</h1>
                <p>{{ number_format($monthCount) }} transaksi pada {{ $period->translatedFormat('F Y') }}</p>
            </div>
            <section class="report-card today-sales-summary" id="sales-summary">
                <div class="report-title">
                    <div>
                        <h2>{{ $salesFrom->isToday() && $salesTo->isToday() ? 'Penjualan hari ini' : 'Ringkasan penjualan' }}</h2>
                        <p>Transaksi kasir pada rentang tanggal terpilih</p>
                    </div><span>{{ $salesFrom->isSameDay($salesTo) ? $salesFrom->translatedFormat('d M') : $salesFrom->translatedFormat('d M').' – '.$salesTo->translatedFormat('d M') }}</span>
                </div>
                <form class="report-inline-range" method="GET" action="{{ route('reports.index') }}"
                    data-sales-range-form>
                    <input type="hidden" name="month" value="{{ $periodKey }}">
                    <input type="hidden" name="activity_from" value="{{ $activityFrom->format('Y-m-d') }}">
                    <input type="hidden" name="activity_to" value="{{ $activityTo->format('Y-m-d') }}">
                    <input type="hidden" name="sales_from" value="{{ $salesFrom->format('Y-m-d') }}" data-range-from>
                    <input type="hidden" name="sales_to" value="{{ $salesTo->format('Y-m-d') }}" data-range-to>
                    <label><span>Rentang penjualan</span>
                        <input type="text" value="" data-report-range-picker
                            data-default-from="{{ $salesFrom->format('Y-m-d') }}"
                            data-default-to="{{ $salesTo->format('Y-m-d') }}"
                            data-max-date="{{ now()->format('Y-m-d') }}" autocomplete="off">
                    </label>
                    <button type="submit">Terapkan</button>
                    @unless($salesFrom->isToday() && $salesTo->isToday())
                        <a href="{{ route('reports.index',[
                            'month'=>$periodKey,
                            'sales_from'=>now()->format('Y-m-d'),
                            'sales_to'=>now()->format('Y-m-d'),
                            'activity_from'=>$activityFrom->format('Y-m-d'),
                            'activity_to'=>$activityTo->format('Y-m-d'),
                        ]) }}" data-sales-range-link>Hari ini</a>
                    @endunless
                </form>
                <div class="cashflow-summary">
                    <article>
                        <span>Transaksi</span><strong>{{ number_format($salesSummary['transactions']) }}</strong><small>{{ number_format($salesSummary['items']) }}
                            item terjual</small>
                    </article>
                    <article class="cash-in"><span>Omset</span><strong>Rp
                            {{ number_format($salesSummary['turnover'], 0, ',', '.') }}</strong><small>Total nilai penjualan
                            pada rentang ini</small></article>
                    <article class="cash-net"><span>Laba</span><strong>Rp
                            {{ number_format($salesSummary['profit'], 0, ',', '.') }}</strong><small>Termasuk biaya admin
                            dan
                            bonus</small></article>
                    <article class="cash-progress"><span>Margin
                            laba</span><strong>{{ $salesMargin }}%</strong><small>Persentase dari omset terpilih</small>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: {{ $salesMargin }}%"></div>
                        </div>
                    </article>
                </div>
            </section>
            <section class="report-summary-grid">
                <a href="{{ route('reports.detail', ['metric' => 'turnover', 'month' => $periodKey]) }}"><span>Total
                        omset</span><strong>Rp {{ number_format($monthTurnover, 0, ',', '.') }}</strong><small>Nilai
                        seluruh
                        penjualan</small><i aria-hidden="true">›</i></a>
                <a href="{{ route('reports.detail', ['metric' => 'profit', 'month' => $periodKey]) }}"><span>Total
                        laba</span><strong>Rp {{ number_format($monthProfit, 0, ',', '.') }}</strong><small>Omset dikurangi
                        modal produk</small><i aria-hidden="true">›</i></a>
                <a href="{{ route('reports.detail', ['metric' => 'stock', 'month' => $periodKey]) }}"><span>Total
                        stok</span><strong>{{ number_format($stock, 0, ',', '.') }} item</strong><small>Stok tersedia saat
                        ini</small><i aria-hidden="true">›</i></a>
                <a href="{{ route('reports.detail', ['metric' => 'stock-value', 'month' => $periodKey]) }}"><span>Nilai
                        modal
                        stok</span><strong>Rp {{ number_format($stockValue, 0, ',', '.') }}</strong><small>Stok × harga
                        modal</small><i aria-hidden="true">›</i></a>
            </section>
            <section class="report-card cashflow-card">
                <div class="report-title">
                    <div>
                        <h2>Arus kas</h2>
                        <p>Semua pergerakan uang pada periode terpilih</p>
                    </div><span>{{ $period->translatedFormat('M Y') }}</span>
                </div>
                <div class="cashflow-summary">
                    <article class="cash-in"><span>Modal awal</span><strong>Rp
                            {{ number_format($capital, 0, ',', '.') }}</strong><small>Setoran modal pada periode
                            ini</small></article>
                    <article class="cash-in"><span>Kas masuk</span><strong>Rp
                            {{ number_format($salesCashIn + $otherCashIn, 0, ',', '.') }}</strong><small>Penjualan Rp
                            {{ number_format($salesCashIn, 0, ',', '.') }} + pemasukan lain Rp
                            {{ number_format($otherCashIn, 0, ',', '.') }}</small></article>
                    <article class="cash-out"><span>Kas keluar</span><strong>Rp
                            {{ number_format($cashOut, 0, ',', '.') }}</strong><small>Pembelian stok dan biaya
                            operasional</small></article>
                    <article class="cash-net"><span>Saldo kas periode</span><strong>Rp
                            {{ number_format($netCash, 0, ',', '.') }}</strong><small>Modal + kas masuk − kas
                            keluar</small>
                    </article>
                </div>
                <a class="report-cash-action" href="{{ route('business.module', 'capital') }}">+ Catat modal awal</a>
            </section>
            @php($max = max(1, $weeks->max('omset')))
            <section class="report-card">
                <div class="report-title">
                    <div>
                        <h2>Tren omzet bulanan</h2>
                        <p>Ringkasan per minggu</p>
                    </div><span>Rp {{ number_format($weeks->sum('omset'), 0, ',', '.') }}</span>
                </div>
                <div class="trend-chart monthly-trend">
                    @foreach ($weeks as $week)
                        <div class="trend-column">
                            <div class="trend-value">
                                {{ $week['omset'] ? number_format($week['omset'] / 1000, 0) . 'K' : '' }}
                            </div>
                            <div class="trend-track"><i style="height:{{ max(5, ($week['omset'] / $max) * 100) }}%"></i>
                            </div>
                            <b>{{ $week['label'] }}</b><small>Tgl {{ $week['range'] }} · {{ $week['count'] }} trx</small>
                        </div>
                    @endforeach
                </div>
            </section>
            <section class="report-card">
                <div class="report-title">
                    <div>
                        <h2>Produk terlaris</h2>
                        <p>Berdasarkan jumlah item pada periode ini</p>
                    </div>
                </div>
                <div class="top-products">
                    @forelse($topProducts as $index=>$item)
                        <div><span>{{ $index + 1 }}</span>
                            <p><b>{{ $item->product?->name }}</b><small>{{ $item->product?->operator }} ·
                                    {{ number_format($item->transaction_count) }} transaksi</small></p><strong>Qty
                                {{ number_format($item->sold) }}<small>Rp
                                    {{ number_format($item->revenue, 0, ',', '.') }}</small></strong>
                    </div>@empty<div class="empty-state">Belum ada data produk pada bulan ini.</div>
                    @endforelse
                </div>
            </section>
            <section class="report-card" id="activity-journal">
                <div class="report-title">
                    <div>
                        <h2>Aktivitas harian</h2>
                        <p>Penjualan, penambahan, dan pengurangan stok</p>
                    </div>
                </div>
                <form class="activity-date-filter" method="GET" action="{{ route('reports.index') }}">
                    <input type="hidden" name="month" value="{{ $periodKey }}">
                    <input type="hidden" name="sales_from" value="{{ $salesFrom->format('Y-m-d') }}">
                    <input type="hidden" name="sales_to" value="{{ $salesTo->format('Y-m-d') }}">
                    <input type="hidden" name="activity_from" value="{{ $activityFrom->format('Y-m-d') }}"
                        data-range-from>
                    <input type="hidden" name="activity_to" value="{{ $activityTo->format('Y-m-d') }}"
                        data-range-to>
                    <label><span>Rentang aktivitas</span><input type="text" value=""
                            data-report-range-picker data-default-from="{{ $activityFrom->format('Y-m-d') }}"
                            data-default-to="{{ $activityTo->format('Y-m-d') }}"
                            data-max-date="{{ now()->format('Y-m-d') }}" autocomplete="off"></label>
                    <button type="submit">Terapkan</button>
                    @unless($activityFrom->isToday() && $activityTo->isToday())
                        <a class="today"
                            href="{{ route('reports.index',[
                                'month'=>$periodKey,
                                'activity_from'=>now()->format('Y-m-d'),
                                'activity_to'=>now()->format('Y-m-d'),
                                'sales_from'=>$salesFrom->format('Y-m-d'),
                                'sales_to'=>$salesTo->format('Y-m-d'),
                            ]) }}"
                            data-activity-date-link>Hari ini</a>
                    @endunless
                </form>
                <div class="activity-filter" aria-label="Filter aktivitas">
                    @foreach([
                        'all' => 'Semua',
                        'sale' => 'Penjualan',
                        'stock-in' => 'Stok masuk',
                        'stock-out' => 'Stok keluar',
                        'refund' => 'Refund',
                    ] as $filter => $label)
                        <button type="button" class="{{ $filter === 'all' ? 'active' : '' }}"
                            data-activity-filter="{{ $filter }}" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">
                            {{ $label }} <span>{{ number_format($activityCounts[$filter]) }}</span>
                        </button>
                    @endforeach
                </div>
                <p class="activity-filter-summary" data-activity-filter-summary>
                    Menampilkan {{ number_format($activityCounts['all']) }} aktivitas
                </p>
                <div class="recent-transactions">
                    @forelse($activities as $activity)
                        @php($item = $activity['record'])
                        @if($activity['kind'] === 'stock')
                            @php($moneyMovement = $item->category === 'Saldo Provider')
                            @php($activityLabel = match($item->type) {
                                'refund' => $item->quantity >= 0 ? 'Penambahan Stok (Refund)' : 'Pengurangan Stok (Refund)',
                                'adjust' => $item->quantity >= 0 ? 'Penambahan Stok (Edit)' : 'Pengurangan Stok (Edit)',
                                'sale', 'wallet_debit', 'wallet_credit' => 'Penjualan',
                                'decrease' => 'Pengurangan Stok',
                                default => 'Penambahan Stok',
                            })
                            <div class="transaction-row stock-activity-row"
                                data-activity-groups="{{ implode(' ', $activity['groups']) }}">
                                <div class="transaction-summary">
                                    <div>
                                        <span>{{ $item->created_at->format('H:i') }}<small>{{ $item->created_at->format('d/m') }}</small></span>
                                        <em class="activity-label {{ str_contains($activityLabel,'Pengurangan') ? 'out' : (str_contains($activityLabel,'Refund') ? 'refund' : 'in') }}">{{ $activityLabel }}</em>
                                        <b>{{ $item->product_name }}</b>
                                        <small>{{ $item->operator }} · {{ $item->note ?? 'Aktivitas stok' }}</small>
                                    </div>
                                    <div class="transaction-meta">
                                        <span class="{{ $item->quantity < 0 ? 'negative' : 'positive' }}">{{ $item->quantity > 0 ? '+' : '−' }}{{ $moneyMovement ? 'Rp '.number_format(abs($item->quantity),0,',','.') : number_format(abs($item->quantity),0,',','.') }}</span>
                                        <small>{{ $moneyMovement ? 'Saldo' : 'Stok' }} {{ number_format($item->stock_after,0,',','.') }}</small>
                                    </div>
                                </div>
                                @if(!$item->transaction_id && in_array($item->type,['initial','increase','decrease'],true))
                                <div class="transaction-actions">
                                    <details class="transaction-edit-toggle">
                                        <summary>Edit aktivitas</summary>
                                        <form class="transaction-edit-form" method="POST"
                                            action="{{ route('reports.stock-movements.edit',$item) }}">
                                            @csrf
                                            <label class="transaction-edit-field">{{ $moneyMovement ? 'Nominal saldo' : 'Jumlah stok' }}
                                                <input type="number" name="quantity" min="1"
                                                    value="{{ abs($item->quantity) }}">
                                            </label>
                                            <small>Perubahan ini langsung menyesuaikan stok atau saldo produk.</small>
                                            <button type="submit" class="primary-btn">Simpan</button>
                                        </form>
                                    </details>
                                </div>
                                @endif
                            </div>
                        @else
                        <div class="transaction-row" data-activity-groups="sale">
                            <div class="transaction-summary">
                                <div>
                                    <span>{{ $item->created_at->format('H:i') }}<small>{{ $item->created_at->format('d/m') }}</small></span>
                                    <em class="activity-label sale">Penjualan</em>
                                    <b>{{ $item->product?->name ?? $item->product_type }}</b>
                                    <small>{{ $item->provider }} · {{ $item->customer_number }}</small>
                                </div>
                                <div class="transaction-meta">
                                    <span>Rp {{ number_format($item->price, 0, ',', '.') }}</span>
                                    <small>Qty {{ number_format($item->quantity ?? 1) }}</small>
                                </div>
                            </div>
                            <div class="transaction-actions">
                                @if ($item->product_id && $item->product && $item->product->category !== 'Kartu Paket')
                                    <details class="transaction-edit-toggle">
                                        <summary>Edit</summary>
                                        <form class="transaction-edit-form" method="POST"
                                            action="{{ route('transactions.edit', $item) }}">
                                            @csrf
                                            <label class="transaction-edit-field">Jumlah
                                                <input type="number" name="quantity" min="1"
                                                    max="{{ (int)$item->quantity + (int)$item->product->stock }}"
                                                    data-edit-limit="qty"
                                                    value="{{ $item->quantity }}">
                                            </label>
                                            <label class="transaction-edit-field">Nomor tujuan
                                                <input type="text" name="customer_number"
                                                    value="{{ $item->customer_number === '-' ? '' : $item->customer_number }}">
                                            </label>
                                            <small class="transaction-edit-alert" hidden></small>
                                            <button type="submit" class="primary-btn">Simpan</button>
                                        </form>
                                    </details>
                                @elseif($item->product_id && $item->product && $item->product->category === 'Kartu Paket')
                                    <span class="transaction-note">Kartu Paket hanya bisa dibatalkan.</span>
                                @else
                                    <details class="transaction-edit-toggle">
                                        <summary>Edit</summary>
                                        <form class="transaction-edit-form" method="POST"
                                            action="{{ route('transactions.edit', $item) }}">
                                            @csrf
                                            @php($balanceMovement = $item->stockMovements->whereIn('type',['wallet_credit','wallet_debit'])->sortByDesc('id')->first())
                                            @php($balanceStock = (int)optional($balanceMovement?->product)->stock)
                                            @php($nominalMin = $balanceMovement && $balanceMovement->quantity > 0 ? max(1000, (int)$item->nominal - $balanceStock) : 1000)
                                            @php($nominalMax = $balanceMovement && $balanceMovement->quantity < 0 ? (int)$item->nominal + $balanceStock : 10000000)
                                            <label class="transaction-edit-field">Nilai transaksi (Rp)
                                                <input type="number" name="nominal" min="{{ $nominalMin }}"
                                                    max="{{ $nominalMax }}" data-edit-limit="saldo"
                                                    value="{{ $item->nominal }}">
                                            </label>
                                            <small class="transaction-edit-alert" hidden></small>
                                            <button type="submit" class="primary-btn">Simpan</button>
                                        </form>
                                    </details>
                                @endif
                                <form class="transaction-refund-form" method="POST" action="{{ route('transactions.refund', $item) }}">
                                    @csrf
                                    <button type="submit" class="secondary-action">Refund</button>
                                </form>
                            </div>
                        </div>
                        @endif
                    @empty
                        <div class="empty-state">Belum ada aktivitas pada rentang ini.</div>
                    @endforelse
                    <div class="empty-state activity-filter-empty" data-activity-filter-empty hidden>
                        Tidak ada aktivitas untuk filter ini.
                    </div>
                </div>
            </section>
        </main>@include('components.mobile-nav')
    </div>
@endsection

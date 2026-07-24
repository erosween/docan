@extends('layouts.app')
@section('title','Laporan — Docan')
@section('body-class','pos-body')
@section('content')
<div class="app-shell report-page">
<header class="topbar"><div class="brand"><span class="brand-mark">D</span><div><b>Laporan Outlet</b><small>{{ auth()->user()->outlet?->name }}</small></div></div><div class="report-period">{{ $period->translatedFormat('F Y') }}</div></header>
<main class="report-main">
<form class="report-filter" method="GET"><label for="report-month">Periode laporan</label><div><input id="report-month" type="month" name="month" value="{{ $periodKey }}"><button type="submit">Tampilkan</button></div></form>
<div class="report-hero"><span class="eyebrow">OMSET BULAN INI</span><h1>Rp {{ number_format($monthTurnover,0,',','.') }}</h1><p>{{ number_format($monthCount) }} transaksi pada {{ $period->translatedFormat('F Y') }}</p></div>
<section class="report-card today-sales-summary">
<div class="report-title"><div><h2>Penjualan hari ini</h2><p>Ringkasan aktivitas kasir sejak pukul 00.00</p></div><span>{{ now()->translatedFormat('d M') }}</span></div>
<div class="cashflow-summary">
<article><span>Transaksi</span><strong>{{ number_format($todaySummary['transactions']) }}</strong><small>{{ number_format($todaySummary['items']) }} item terjual</small></article>
<article class="cash-in"><span>Omset</span><strong>Rp {{ number_format($todaySummary['turnover'],0,',','.') }}</strong><small>Total nilai penjualan hari ini</small></article>
<article class="cash-net"><span>Laba</span><strong>Rp {{ number_format($todaySummary['profit'],0,',','.') }}</strong><small>Termasuk biaya admin dan bonus</small></article>
</div>
</section>
<section class="report-summary-grid">
<a href="{{ route('reports.detail',['metric'=>'turnover','month'=>$periodKey]) }}"><span>Total omset</span><strong>Rp {{ number_format($monthTurnover,0,',','.') }}</strong><small>Nilai seluruh penjualan</small><i aria-hidden="true">›</i></a>
<a href="{{ route('reports.detail',['metric'=>'profit','month'=>$periodKey]) }}"><span>Total laba</span><strong>Rp {{ number_format($monthProfit,0,',','.') }}</strong><small>Omset dikurangi modal produk</small><i aria-hidden="true">›</i></a>
<a href="{{ route('reports.detail',['metric'=>'stock','month'=>$periodKey]) }}"><span>Total stok</span><strong>{{ number_format($stock,0,',','.') }} item</strong><small>Stok tersedia saat ini</small><i aria-hidden="true">›</i></a>
<a href="{{ route('reports.detail',['metric'=>'stock-value','month'=>$periodKey]) }}"><span>Nilai modal stok</span><strong>Rp {{ number_format($stockValue,0,',','.') }}</strong><small>Stok × harga modal</small><i aria-hidden="true">›</i></a>
</section>
<section class="report-card cashflow-card"><div class="report-title"><div><h2>Arus kas</h2><p>Semua pergerakan uang pada periode terpilih</p></div><span>{{ $period->translatedFormat('M Y') }}</span></div><div class="cashflow-summary"><article class="cash-in"><span>Kas masuk</span><strong>Rp {{ number_format($salesCashIn + $otherCashIn,0,',','.') }}</strong><small>Penjualan Rp {{ number_format($salesCashIn,0,',','.') }} + pemasukan lain Rp {{ number_format($otherCashIn,0,',','.') }}</small></article><article class="cash-out"><span>Kas keluar</span><strong>Rp {{ number_format($cashOut,0,',','.') }}</strong><small>Pembelian stok dan biaya operasional</small></article><article class="cash-net"><span>Arus kas bersih</span><strong>Rp {{ number_format($netCash,0,',','.') }}</strong><small>Kas masuk dikurangi kas keluar</small></article></div></section>
@php($max=max(1,$weeks->max('omset')))
<section class="report-card"><div class="report-title"><div><h2>Tren omzet bulanan</h2><p>Ringkasan per minggu</p></div><span>Rp {{ number_format($weeks->sum('omset'),0,',','.') }}</span></div><div class="trend-chart monthly-trend">@foreach($weeks as $week)<div class="trend-column"><div class="trend-value">{{ $week['omset'] ? number_format($week['omset']/1000,0).'K' : '' }}</div><div class="trend-track"><i style="height:{{ max(5,($week['omset']/$max)*100) }}%"></i></div><b>{{ $week['label'] }}</b><small>Tgl {{ $week['range'] }} · {{ $week['count'] }} trx</small></div>@endforeach</div></section>
<section class="report-card"><div class="report-title"><div><h2>Produk terlaris</h2><p>Berdasarkan jumlah item pada periode ini</p></div></div><div class="top-products">@forelse($topProducts as $index=>$item)<div><span>{{ $index+1 }}</span><p><b>{{ $item->product?->name }}</b><small>{{ $item->product?->operator }} · {{ number_format($item->transaction_count) }} transaksi</small></p><strong>Qty {{ number_format($item->sold) }}<small>Rp {{ number_format($item->revenue,0,',','.') }}</small></strong></div>@empty<div class="empty-state">Belum ada data produk pada bulan ini.</div>@endforelse</div></section>
<section class="report-card"><div class="report-title"><div><h2>Transaksi terbaru</h2><p>Aktivitas pada periode terpilih</p></div></div><div class="recent-transactions">@forelse($recent as $item)<div><span>{{ $item->created_at->format('H:i') }}<small>{{ $item->created_at->format('d/m') }}</small></span><p><b>{{ $item->product?->name ?? $item->product_type }}</b><small>{{ $item->provider }} · {{ $item->customer_number }}</small></p><strong><em>Qty {{ number_format($item->quantity ?? 1) }}</em>Rp {{ number_format($item->price,0,',','.') }}</strong></div>@empty<div class="empty-state">Belum ada transaksi pada bulan ini.</div>@endforelse</div></section>
</main>@include('components.mobile-nav')</div>
@endsection

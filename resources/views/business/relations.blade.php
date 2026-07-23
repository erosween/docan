@extends('layouts.app')
@section('title','Relasi — Docan')
@section('body-class','pos-body')
@section('content')
<div class="app-shell business-page">
    <header class="topbar">
        <div class="brand"><span class="brand-mark">D</span><div><b>Relasi</b><small>{{ auth()->user()->outlet?->name }}</small></div></div>
    </header>
    <main class="business-main relations-main">
        <span class="eyebrow">RELASI OUTLET</span>
        <h1>Pelanggan & mitra</h1>
        <p class="relations-lead">Kelola kontak, piutang, dan hutang outlet dalam satu tempat.</p>
        <section class="relations-grid">
            <a href="{{ route('business.module','customer') }}"><span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-5 3-8 8-8s8 3 8 8"/></svg></span><div><b>Pelanggan</b><small>{{ number_format($customerCount) }} pelanggan</small></div></a>
            <a href="{{ route('business.module','supplier') }}"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h18v11H3zM5 10V6l7-3 7 3v4M8 14h8M8 18h5"/></svg></span><div><b>Supplier</b><small>{{ number_format($supplierCount) }} supplier</small></div></a>
            <a href="{{ route('business.module','receivable') }}"><span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="14" rx="3"/><path d="M16 13H8m0 0 3-3m-3 3 3 3M7 6V4h10v2"/></svg></span><div><b>Piutang</b><small>Rp {{ number_format($receivableTotal,0,',','.') }}</small></div></a>
            <a href="{{ route('business.module','payable') }}"><span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="14" rx="3"/><path d="M8 13h8m0 0-3-3m3 3-3 3M7 6V4h10v2"/></svg></span><div><b>Hutang</b><small>Rp {{ number_format($payableTotal,0,',','.') }}</small></div></a>
        </section>
    </main>
    @include('components.mobile-nav')
</div>
@endsection

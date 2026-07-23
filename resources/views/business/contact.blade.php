@extends('layouts.app')
@section('title',$contact->name.' — Docan')
@section('body-class','pos-body')
@section('content')
<div class="app-shell business-page">
    <header class="topbar"><a class="back-btn" href="{{ route('business.module',$contact->type) }}">←</a><div class="brand"><div><b>Detail {{ $contact->type==='customer'?'Pelanggan':'Supplier' }}</b><small>{{ auth()->user()->outlet?->name }}</small></div></div></header>
    <main class="business-main">
        <section class="contact-detail-card">
            <span class="contact-detail-icon">@if($contact->type==='customer')<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-5 3-8 8-8s8 3 8 8"/></svg>@else<svg viewBox="0 0 24 24"><path d="M3 10h18v11H3zM5 10V6l7-3 7 3v4M8 14h8M8 18h5"/></svg>@endif</span>
            <div><small>{{ $contact->type==='customer'?'PELANGGAN':'SUPPLIER' }}</small><h1>{{ $contact->name }}</h1><p>{{ $contact->phone ?: 'Nomor telepon belum dicatat' }}</p></div>
            @if($contact->address)<address>{{ $contact->address }}</address>@endif
        </section>
        <section class="business-list"><h2>Riwayat transaksi</h2>
            @forelse($entries as $entry)<article><div><b>{{ $entry->description }}</b><small>{{ $entry->entry_date?->format('d/m/Y') }} · {{ ucfirst(str_replace('-',' ',$entry->type)) }}</small></div><strong>Rp {{ number_format($entry->amount,0,',','.') }}</strong></article>
            @empty<p class="empty-state">Belum ada transaksi yang terhubung dengan relasi ini.</p>@endforelse
        </section>{{ $entries->links() }}
    </main>
    @include('components.mobile-nav')
</div>
@endsection

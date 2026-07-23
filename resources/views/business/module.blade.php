@extends('layouts.app')
@php($labels=['purchase'=>'Pembelian','cash-in'=>'Kas Masuk','cash-out'=>'Kas Keluar','customer'=>'Pelanggan','supplier'=>'Supplier','receivable'=>'Piutang','payable'=>'Hutang','categories'=>'Kategori'])
@section('title',($labels[$module]??'Bisnis').' — Docan')
@section('body-class','pos-body')
@section('content')
<div class="app-shell business-page">
    <header class="topbar">
        <a class="back-btn" href="{{ in_array($module,['customer','supplier','receivable','payable']) ? route('business.relations') : route('reports.index') }}">←</a>
        <div class="brand"><div><b>{{ $labels[$module] }}</b><small>{{ auth()->user()->outlet?->name }}</small></div></div>
    </header>
    <main class="business-main">
        @if(session('success'))<div class="alert success">✓ {{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
        <section class="business-entry-form">
            <h1>{{ in_array($module,['customer','supplier'])?'Tambah '.$labels[$module]:($module==='categories'?'Tambah kategori':'Catat '.$labels[$module]) }}</h1>
            <form method="POST" action="{{ route('business.store',$module) }}">@csrf
                @if(in_array($module,['customer','supplier']))
                    <label>Nama<input name="name" required></label>
                    <label>Nomor telepon<input name="phone" inputmode="tel"></label>
                    <label>Alamat<textarea name="address"></textarea></label>
                @elseif($module==='categories')
                    <label>Nama kategori<input name="name" required></label>
                @else
                    <label>Keterangan<input name="description" required></label>
                    <label>Nominal<input name="amount" inputmode="numeric" data-money-input required></label>
                    <label>Tanggal<input type="date" name="entry_date" value="{{ date('Y-m-d') }}" required></label>
                    @if(in_array($module,['purchase','receivable','payable']))
                        <label>Relasi<select name="contact_id"><option value="">Tanpa relasi</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }}</option>@endforeach</select></label>
                    @endif
                    @if(in_array($module,['receivable','payable']))
                        <label>Jatuh tempo<input type="date" name="due_date"></label><input type="hidden" name="status" value="open">
                    @endif
                @endif
                <button>Simpan</button>
            </form>
        </section>
        <section class="business-list">
            <h2>Riwayat {{ $labels[$module] }}</h2>
            @forelse($items as $item)
                @if(in_array($module,['customer','supplier']))<a class="business-list-link" href="{{ route('business.contact',$item) }}"><article><div><b>{{ $item->name }}</b><small>{{ $item->phone ?: 'Nomor belum dicatat' }}</small></div><strong aria-hidden="true">›</strong></article></a>
                @else<article><div><b>{{ $item->description }}</b><small>{{ optional($item->entry_date)->format('d/m/Y') }}{{ $item->contact?' · '.$item->contact->name:'' }}</small></div><strong>Rp {{ number_format($item->amount,0,',','.') }}</strong></article>@endif
            @empty
                <p class="empty-state">Belum ada data.</p>
            @endforelse
        </section>
        {{ $items->links() }}
    </main>
    @include('components.mobile-nav')
</div>
@endsection

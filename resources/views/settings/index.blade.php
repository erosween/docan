@extends('layouts.app')
@section('title','Pengaturan — Docan')
@section('body-class','pos-body')
@section('content')
<div class="app-shell settings-page">
    <header class="topbar"><div class="brand"><span class="brand-mark">D</span><div><b>Pengaturan</b><small>Docan · Dompet Canggih</small></div></div></header>
    <main class="settings-main">
        @if(session('success'))<div class="alert password-success">✓ {{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
        <div class="outlet-profile"><div class="profile-avatar">{{ strtoupper(substr(auth()->user()->outlet?->name ?? 'D',0,1)) }}</div><div><span>OUTLET AKTIF</span><h1>{{ auth()->user()->outlet?->name }}</h1><p>{{ auth()->user()->name }}</p></div></div>
        <section class="settings-group">
            <h2>Akun & outlet</h2>
            <div class="setting-row"><span>Nama outlet</span><b>{{ auth()->user()->outlet?->name }}</b></div>
            <div class="setting-row"><span>ID login akun</span><b>{{ auth()->user()->login_id }}</b></div>
            <div class="setting-row"><span>Peran akun</span><b>{{ auth()->user()->isOwner() ? 'Owner' : 'Frontliner' }}</b></div>
        </section>
        @if(auth()->user()->isOwner())
        <section class="settings-group frontliner-settings">
            <div class="settings-title"><div><h2>Akun Frontliner</h2><p>Setiap Frontliner memiliki ID dan password sendiri. Stok tetap memakai stok outlet.</p></div><span>{{ $frontliners->count() }} AKUN</span></div>
            <form method="POST" action="{{ route('settings.frontliners.store') }}" class="frontliner-form">@csrf
                <label>Nama Frontliner<input name="name" value="{{ old('name') }}" placeholder="Rani Shift Pagi" required></label>
                <label>ID login<input name="login_id" value="{{ old('login_id', auth()->user()->outlet?->login_id.'-FL'.str_pad($frontliners->count()+1,2,'0',STR_PAD_LEFT)) }}" autocapitalize="characters" required></label>
                <label>Password awal<span class="settings-password"><input id="frontliner_password" type="password" name="password" minlength="8" required><button type="button" data-toggle-password data-target="frontliner_password" aria-label="Lihat password">◉</button></span></label>
                <label>Ulangi password<span class="settings-password"><input id="frontliner_password_confirmation" type="password" name="password_confirmation" minlength="8" required><button type="button" data-toggle-password data-target="frontliner_password_confirmation" aria-label="Lihat password">◉</button></span></label>
                <button>Tambah Frontliner</button>
            </form>
            <div class="frontliner-list">
                @forelse($frontliners as $frontliner)
                <div class="frontliner-account {{ optional($selectedFrontliner)->id === $frontliner->id ? 'active' : '' }}">
                    <a href="{{ route('settings.index',['frontliner'=>$frontliner->id]) }}"><b>{{ $frontliner->name }}</b><small><span class="role-badge">Frontliner</span> {{ $frontliner->login_id }}</small><strong>Rp {{ number_format($frontliner->sales_total ?? 0,0,',','.') }}</strong><em>{{ $frontliner->transactions_count }} transaksi · Laba Rp {{ number_format($frontliner->profit_total ?? 0,0,',','.') }}</em></a>
                    <form method="POST" action="{{ route('settings.frontliners.destroy',$frontliner) }}">@csrf @method('DELETE')<button type="submit">Hapus</button></form>
                </div>
                @empty<p class="empty-state">Belum ada akun Frontliner.</p>@endforelse
            </div>
            @if($selectedFrontliner)
            <div class="frontliner-detail"><div class="settings-title"><div><h3>{{ $selectedFrontliner->name }}</h3><p>Aktivitas penjualan akun Frontliner ini.</p></div><a href="{{ route('products.index',['view'=>'all']) }}">Lihat produk outlet</a></div>
                @forelse($selectedFrontliner->transactions as $transaction)<div class="frontliner-sale"><span><b>{{ $transaction->product?->name ?? $transaction->product_type }}</b><small>{{ $transaction->created_at->format('d/m/Y H:i') }} · Qty {{ $transaction->quantity }}</small></span><strong>Rp {{ number_format($transaction->price,0,',','.') }}</strong></div>@empty<p class="empty-state">Belum ada transaksi.</p>@endforelse
            </div>
            @endif
        </section>
        @endif
        <section class="settings-group support-settings"><div class="settings-title"><div><h2>Bantuan & Helpdesk</h2><p>Butuh bantuan penggunaan Docan? Hubungi tim support melalui WhatsApp.</p></div><span>SUPPORT</span></div><a class="support-whatsapp" href="https://wa.me/628116289299?text=Halo%20Helpdesk%20Docan%2C%20saya%20butuh%20bantuan." target="_blank" rel="noopener"><span class="support-whatsapp-icon"><img src="/img/whatsapp.svg" alt="" width="44" height="44"></span><div><b>Hubungi Helpdesk Docan</b><small>0811 6289 299 · Buka WhatsApp</small></div></a></section>
        <section class="settings-group password-settings"><div class="settings-title"><div><h2>Ganti password</h2><p>Gunakan minimal 8 karakter agar akun tetap aman.</p></div><span>KEAMANAN</span></div>
            <form method="POST" action="{{ route('settings.password') }}">@csrf @method('PUT')
                @foreach([['current_password','Password saat ini','current-password'],['password','Password baru','new-password'],['password_confirmation','Ulangi password baru','new-password']] as [$field,$label,$autocomplete])
                <label>{{ $label }}<span class="settings-password"><input id="{{ $field }}" type="password" name="{{ $field }}" minlength="8" autocomplete="{{ $autocomplete }}" required><button type="button" data-toggle-password data-target="{{ $field }}" aria-label="Lihat password">◉</button></span></label>
                @endforeach
                <button>Perbarui password</button>
            </form>
        </section>
        <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-wide">Keluar dari akun</button></form>
    </main>
    @include('components.mobile-nav')
</div>
<script>document.querySelectorAll('[data-toggle-password][data-target]').forEach(function(button){button.addEventListener('click',function(){var input=document.getElementById(button.dataset.target);if(!input)return;input.type=input.type==='password'?'text':'password';button.classList.toggle('visible',input.type==='text');button.setAttribute('aria-label',input.type==='text'?'Sembunyikan password':'Lihat password');});});</script>
@endsection

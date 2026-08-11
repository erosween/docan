@extends('layouts.app')
@section('title','Outlet SF — Docan')
@section('body-class','sf-body')
@section('content')
<main class="sf-shell">
    <header class="sf-header">
        <div class="sf-brand"><span>D</span><div><b>Dashboard SF</b><small>{{ auth()->user()->name }} · {{ auth()->user()->sf_code }}</small></div></div>
        <form method="POST" action="{{ route('logout') }}">@csrf<button>Keluar</button></form>
    </header>
    <section class="sf-hero"><div><span>OUTLET BINAAN</span><h1>Kontrol outlet SF</h1><p>Periksa pendaftaran baru dan kelola outlet yang aktif di bawah kode Anda.</p></div><div class="sf-stats"><article><b>{{ $outlets->count() }}</b><small>Total outlet</small></article><article><b>{{ $outlets->where('status','pending')->count() }}</b><small>Menunggu</small></article><article><b>{{ $outlets->where('status','active')->count() }}</b><small>Aktif</small></article></div></section>
    @if(session('success'))<div class="alert success">{{ session('success') }}</div>@endif
    <section class="sf-outlets">
        <div class="sf-section-title"><div><h2>Daftar outlet</h2><p>Hanya outlet yang mendaftar menggunakan SF Code Anda.</p></div></div>
        <div class="sf-outlet-grid">
            @forelse($outlets as $outlet)
                @php($owner=$outlet->users->firstWhere('role','owner'))
                @php($lastSale=$outlet->transactions_max_created_at ? \Illuminate\Support\Carbon::parse($outlet->transactions_max_created_at) : null)
                <article class="sf-outlet-card status-{{ $outlet->status }}">
                    <div class="sf-outlet-head"><span>{{ strtoupper(substr($outlet->name,0,1)) }}</span><div><b>{{ $outlet->name }}</b><small>{{ $outlet->login_id }}</small></div><em>{{ $outlet->status === 'pending' ? 'Menunggu' : ($outlet->status === 'active' ? 'Aktif' : 'Nonaktif') }}</em></div>
                    <dl><div><dt>Pemilik</dt><dd>{{ $owner?->name ?: '—' }}</dd></div><div><dt>Nomor RS</dt><dd>{{ $owner?->phone ?: '—' }}</dd></div><div><dt>Wilayah</dt><dd>{{ $outlet->district }}, {{ $outlet->regency }}</dd></div><div><dt>Terdaftar</dt><dd>{{ $outlet->created_at->format('d/m/Y H:i') }}</dd></div><div><dt>Produk</dt><dd>{{ number_format($outlet->products_count) }}</dd></div></dl>
                    <section class="sf-sales-summary">
                        <header><div><span>CATATAN JUALAN</span><b>Ringkasan outlet</b></div><em class="{{ $lastSale ? 'recording' : 'empty' }}">{{ $lastSale ? 'Sudah mencatat' : 'Belum mencatat' }}</em></header>
                        <div class="sf-sales-metrics">
                            <div><small>Transaksi</small><strong>{{ number_format($outlet->transactions_count) }}</strong></div>
                            <div><small>Item terjual</small><strong>{{ number_format($outlet->transactions_sum_quantity ?? 0) }}</strong></div>
                            <div><small>Total omzet</small><strong>Rp {{ number_format($outlet->transactions_sum_price ?? 0, 0, ',', '.') }}</strong></div>
                        </div>
                        <p>{{ $lastSale ? 'Terakhir mencatat '.$lastSale->locale('id')->diffForHumans().' · '.$lastSale->format('d/m/Y H:i') : 'Belum ada transaksi penjualan yang dicatat.' }}</p>
                    </section>
                    <form method="POST" action="{{ route('sf.outlets.status',$outlet) }}">@csrf @method('PUT')
                        @if($outlet->status !== 'active')<button name="status" value="active" class="approve">{{ $outlet->status === 'pending' ? '✓ Setujui outlet' : 'Aktifkan kembali' }}</button>@else<button name="status" value="inactive" class="deactivate">Nonaktifkan outlet</button>@endif
                    </form>
                </article>
            @empty
                <div class="sf-empty"><b>Belum ada outlet</b><p>Outlet yang mendaftar menggunakan kode {{ auth()->user()->sf_code }} akan muncul di sini.</p></div>
            @endforelse
        </div>
    </section>
</main>
@endsection

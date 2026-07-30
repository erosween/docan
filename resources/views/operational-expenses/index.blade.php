@extends('layouts.app')
@section('title','Biaya Operasional — Docan')
@section('body-class','pos-body')
@section('content')
<div class="app-shell expense-page">
    <header class="topbar"><div class="brand"><span class="brand-mark">D</span><div><b>Biaya Operasional</b><small>{{ auth()->user()->outlet?->name }}</small></div></div></header>
    <main class="expense-main">
        @if(session('success'))<div class="toast success">✓ {{ session('success') }}</div>@endif
        @if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
        <section class="expense-hero"><div><span class="eyebrow">TOTAL {{ mb_strtoupper($month->translatedFormat('F Y')) }}</span><h1>Rp {{ number_format($total,0,',','.') }}</h1><p>Catat biaya rutin agar laba bersih outlet lebih akurat.</p></div><form method="GET"><input type="month" name="month" value="{{ $monthKey }}"><button>Tampilkan</button></form></section>
        <div class="expense-layout">
            <section class="expense-form-card"><div class="expense-section-title"><span>CATAT BIAYA</span><h2>Biaya operasional baru</h2></div>
                <form method="POST" action="{{ route('operational-expenses.store') }}">@csrf
                    <label>Kategori<select name="category_id" required><option value="">Pilih kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id')==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
                    <label>Keterangan<input name="description" value="{{ old('description') }}" maxlength="180" placeholder="Contoh: Bensin antar pesanan" required></label>
                    <div class="expense-form-row"><label>Nominal<input name="amount" value="{{ old('amount') }}" inputmode="numeric" data-money-input placeholder="Rp 0" required></label><label>Tanggal<input type="date" name="entry_date" value="{{ old('entry_date',now()->format('Y-m-d')) }}" max="{{ now()->format('Y-m-d') }}" required></label></div>
                    <button class="primary-btn">Simpan biaya</button>
                </form>
                <form class="expense-category-form" method="POST" action="{{ route('operational-expenses.categories.store') }}">@csrf<label>Tambah kategori lain<input name="name" maxlength="100" placeholder="Nama kategori biaya" required></label><button>+ Tambah</button></form>
            </section>
            <section class="expense-summary-card"><div class="expense-section-title"><span>RINGKASAN</span><h2>Biaya per kategori</h2></div><div class="expense-category-totals">@forelse($categoryTotals as $item)<div><span>{{ $item->name }}</span><strong>Rp {{ number_format($item->total,0,',','.') }}</strong></div>@empty<p>Belum ada biaya pada periode ini.</p>@endforelse</div></section>
        </div>
        <section class="expense-history"><div class="expense-section-title"><span>RIWAYAT</span><h2>Pengeluaran {{ $month->translatedFormat('F Y') }}</h2></div>
            <div class="expense-list">@forelse($expenses as $expense)<article><div class="expense-date"><b>{{ $expense->entry_date->format('d') }}</b><span>{{ $expense->entry_date->translatedFormat('M') }}</span></div><div class="expense-copy"><span>{{ $expense->category?->name ?? 'Tanpa kategori' }}</span><h3>{{ $expense->description }}</h3><small>Dicatat {{ $expense->created_at->format('d/m/Y H:i') }}</small></div><strong>Rp {{ number_format($expense->amount,0,',','.') }}</strong><details><summary>Edit</summary><form method="POST" action="{{ route('operational-expenses.update',$expense) }}">@csrf @method('PUT')<select name="category_id" required>@foreach($categories as $category)<option value="{{ $category->id }}" @selected($expense->category_id===$category->id)>{{ $category->name }}</option>@endforeach</select><input name="description" value="{{ $expense->description }}" required><input name="amount" inputmode="numeric" data-money-input value="{{ number_format($expense->amount,0,',','.') }}" required><input type="date" name="entry_date" value="{{ $expense->entry_date->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" required><button>Simpan</button></form><form method="POST" action="{{ route('operational-expenses.destroy',$expense) }}">@csrf @method('DELETE')<button class="danger">Hapus biaya</button></form></details></article>@empty<div class="empty-state"><b>Belum ada biaya operasional</b><p>Biaya yang dicatat akan muncul di sini.</p></div>@endforelse</div>
            @if($expenses->hasPages())<nav class="pager"><a class="{{ $expenses->onFirstPage()?'disabled':'' }}" href="{{ $expenses->previousPageUrl()?:'#' }}">← Sebelumnya</a><span>Halaman {{ $expenses->currentPage() }} dari {{ $expenses->lastPage() }}</span><a class="{{ $expenses->hasMorePages()?'':'disabled' }}" href="{{ $expenses->nextPageUrl()?:'#' }}">Berikutnya →</a></nav>@endif
        </section>
    </main>
    @include('components.mobile-nav')
</div>
@endsection

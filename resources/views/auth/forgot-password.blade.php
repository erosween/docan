@extends('layouts.app')
@section('title','Lupa Password — Docan') @section('body-class','login-body')
@section('content')
<main class="login-shell"><section class="login-form-wrap reset-auth-wrap"><div class="login-form"><a href="{{ route('login') }}" class="back-btn" aria-label="Kembali">←</a><div class="mobile-brand visible"><span class="brand-mark">D</span><b>Docan</b></div><span class="eyebrow green">PEMULIHAN AKUN</span><h2>Lupa password?</h2><p class="muted">Masukkan email pemilik outlet. Kami akan mengirim tautan aman untuk membuat password baru.</p>
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('password.email') }}">@csrf<div class="form-group"><label for="email">Email pemilik</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus></div><button class="primary-btn" type="submit">Kirim tautan reset</button></form><p class="login-link"><a href="{{ route('login') }}">Kembali ke halaman masuk</a></p></div></section></main>
@if(session('status'))<div class="reset-confirmation" id="reset-confirmation"><section role="dialog" aria-modal="true" aria-labelledby="reset-confirmation-title"><span>✓</span><small>EMAIL TERKIRIM</small><h2 id="reset-confirmation-title">Cek email Anda</h2><p>{{ session('status') }}</p><button type="button" id="close-reset-confirmation">Mengerti</button><a href="{{ route('login') }}">Kembali ke halaman masuk</a></section></div>@endif
@if(session('status'))<script>document.querySelector('#close-reset-confirmation')?.addEventListener('click',()=>document.querySelector('#reset-confirmation')?.remove());</script>@endif
@endsection

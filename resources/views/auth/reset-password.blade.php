@extends('layouts.app')
@section('title','Password Baru — Docan') @section('body-class','login-body')
@section('content')
<main class="login-shell"><section class="login-form-wrap reset-auth-wrap"><div class="login-form"><div class="mobile-brand visible"><span class="brand-mark">D</span><b>Docan</b></div><span class="eyebrow green">KEAMANAN AKUN</span><h2>Buat password baru</h2><p class="muted">Gunakan password yang kuat dan berbeda dari password sebelumnya.</p>
@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('password.update') }}" id="reset-password-form">@csrf<input type="hidden" name="token" value="{{ $token }}"><div class="form-group"><label for="email">Email pemilik</label><input id="email" type="email" name="email" value="{{ old('email',$email) }}" autocomplete="email" required></div><div class="form-group"><label for="password">Password baru</label><div class="password-field"><input id="password" type="password" name="password" minlength="8" autocomplete="new-password" required><button type="button" data-toggle-password data-target="password" aria-label="Tampilkan password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div><small class="password-requirements" id="password-requirements">Minimal 8 karakter, huruf besar-kecil, angka, dan simbol.</small></div><div class="form-group"><label for="password_confirmation">Ulangi password baru</label><div class="password-field"><input id="password_confirmation" type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required><button type="button" data-toggle-password data-target="password_confirmation" aria-label="Tampilkan ulang password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div><small class="password-match" id="password-match">Masukkan ulang password.</small></div><button class="primary-btn" id="reset-password-submit" type="submit" disabled>Simpan password baru</button></form></div></section></main>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    const password=document.querySelector('#password'),confirmation=document.querySelector('#password_confirmation');
    const requirements=document.querySelector('#password-requirements'),match=document.querySelector('#password-match');
    const submit=document.querySelector('#reset-password-submit');
    const validate=()=>{
        const started=password.value!=='';
        const secure=password.value.length>=8&&/[a-z]/.test(password.value)&&/[A-Z]/.test(password.value)&&/\d/.test(password.value)&&/[^A-Za-z0-9]/.test(password.value);
        requirements.classList.toggle('valid',secure);
        requirements.classList.toggle('invalid',started&&!secure);
        requirements.textContent=secure?'✓ Password memenuhi standar keamanan.':(started?'Password belum memenuhi semua syarat.':'Minimal 8 karakter, huruf besar-kecil, angka, dan simbol.');
        const same=confirmation.value!==''&&password.value===confirmation.value;
        confirmation.setCustomValidity(confirmation.value!==''&&!same?'Password belum sama.':'');
        match.classList.toggle('valid',same);
        match.classList.toggle('invalid',confirmation.value!==''&&!same);
        match.textContent=confirmation.value===''?(started?'Ulangi password untuk memastikan sudah sama.':'Masukkan ulang password.'):(same?'✓ Password sudah sama.':'Password belum sama.');
        submit.disabled=!(secure&&same);
    };
    password.addEventListener('input',validate);
    confirmation.addEventListener('input',validate);
    validate();
});
</script>
@endsection

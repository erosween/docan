@extends('layouts.app')
@section('title','Buat Akun Outlet — Docan') @section('body-class','login-body')
@section('content')
<main class="register-shell"><section class="register-panel"><a href="{{ route('login') }}" class="back-btn" aria-label="Kembali">←</a><div class="mobile-brand visible"><span class="brand-mark">D</span><b>Docan</b></div><span class="eyebrow green">DAFTAR OUTLET</span><h1>Buat akun outlet</h1><p class="muted">Satu akun Owner untuk mulai mengatur kasir, produk, stok, dan laporan.</p>@if($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('register.submit') }}" class="register-form">@csrf
<div class="form-row"><div class="form-group"><label for="outlet_name">Nama outlet</label><input id="outlet_name" name="outlet_name" value="{{ old('outlet_name') }}" maxlength="120" required></div><div class="form-group"><label for="owner_name">Nama pemilik</label><input id="owner_name" name="owner_name" value="{{ old('owner_name') }}" maxlength="120" required></div></div>
<div class="form-row region-row">
    <div class="form-group searchable-region">
        <label for="regency">Kabupaten / Kota</label>
        <div class="region-combobox" data-region-combobox>
            <input id="regency" name="regency" value="{{ old('regency') }}" placeholder="Cari Kabupaten/Kota" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="regency-options" aria-expanded="false" required>
            <button class="region-clear" type="button" aria-label="Hapus pilihan Kabupaten/Kota" title="Ganti Kabupaten/Kota" hidden>×</button>
            <button class="region-toggle" type="button" aria-label="Buka daftar Kabupaten/Kota" tabindex="-1">⌄</button>
            <div id="regency-options" class="region-options" role="listbox" hidden>
                @foreach(array_keys($outletRegions) as $regency)
                    <button type="button" role="option" data-value="{{ $regency }}">{{ $regency }}</button>
                @endforeach
                <p class="region-empty" hidden>Kabupaten/Kota tidak ditemukan.</p>
            </div>
        </div>
        <small>Pilih Kabupaten/Kota sesuai daftar wilayah operasional.</small>
    </div>
    <div class="form-group searchable-region">
        <label for="district">Kecamatan</label>
        <div class="region-combobox" data-district-combobox>
            <input id="district" name="district" value="{{ old('district') }}" data-old-value="{{ old('district') }}" placeholder="Pilih Kabupaten/Kota dahulu" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="district-options" aria-expanded="false" required disabled>
            <button class="region-clear" type="button" aria-label="Hapus pilihan Kecamatan" title="Ganti Kecamatan" hidden>×</button>
            <button class="region-toggle" type="button" aria-label="Buka daftar Kecamatan" tabindex="-1" disabled>⌄</button>
            <div id="district-options" class="region-options" role="listbox" hidden>
                <p class="region-empty" hidden>Kecamatan tidak ditemukan.</p>
            </div>
        </div>
        <small>Daftar kecamatan mengikuti Kabupaten/Kota.</small>
    </div>
</div>
<div class="form-row"><div class="form-group"><label for="login_id">User Login</label><input id="login_id" name="login_id" value="{{ old('login_id') }}" placeholder="Contoh: TOKO-001" autocapitalize="characters" maxlength="40" required><small>Digunakan untuk masuk ke Docan.</small></div><div class="form-group"><label for="email">Email pemilik</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" placeholder="nama@email.com" required aria-describedby="email-availability"><small id="email-availability" class="password-match" aria-live="polite">Digunakan untuk reset password akun Owner.</small></div></div>
<div class="form-group"><label for="sf_code">SF Code</label><input id="sf_code" name="sf_code" value="{{ old('sf_code') }}" placeholder="Contoh: SF-BENGKULU-01" autocapitalize="characters" autocomplete="off" maxlength="40" required aria-describedby="sf-code-availability"><small id="sf-code-availability" class="password-match" aria-live="polite">Outlet akan masuk ke akun SF ini untuk diperiksa dan disetujui.</small></div>
<div class="form-group"><label for="rs_number">Nomor RS</label><input id="rs_number" name="rs_number" value="{{ old('rs_number') }}" inputmode="numeric" autocomplete="off" pattern="[0-9]{6,20}" placeholder="Contoh: 12345678" required><small>Gunakan 6–20 angka tanpa spasi.</small></div>
<div class="form-row"><div class="form-group"><label for="password">Kata sandi</label><div class="password-field"><input id="password" type="password" name="password" autocomplete="new-password" minlength="8" required><button type="button" data-toggle-password data-target="password" aria-label="Tampilkan kata sandi"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div><small class="password-requirements" id="password-requirements">Minimal 8 karakter, huruf besar-kecil, angka, dan simbol.</small></div><div class="form-group"><label for="password_confirmation">Ulangi kata sandi</label><div class="password-field"><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required><button type="button" data-toggle-password data-target="password_confirmation" aria-label="Tampilkan ulang kata sandi"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div><small class="password-match" id="password-match">Masukkan ulang kata sandi.</small></div></div>
<label class="consent-field"><input id="terms" type="checkbox" name="terms" value="1" @checked(old('terms'))><span>Saya telah membaca dan menyetujui <a href="{{ route('legal.terms') }}" target="_blank">Syarat dan Ketentuan</a> serta <a href="{{ route('legal.privacy') }}" target="_blank">Kebijakan Privasi Docan</a>.</span></label>
<button class="primary-btn" id="register-submit" type="submit" disabled>Daftar Sekarang</button><p class="login-link">Sudah memiliki akun? <a href="{{ route('login') }}">Masuk</a></p></form></section></main>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const regions = @json($outletRegions);
    const regency = document.querySelector('#regency');
    const district = document.querySelector('#district');
    const combobox = document.querySelector('[data-region-combobox]');
    const districtCombobox = document.querySelector('[data-district-combobox]');
    const options = document.querySelector('#regency-options');
    const districtOptions = document.querySelector('#district-options');
    const optionButtons = [...options.querySelectorAll('[data-value]')];
    const emptyMessage = options.querySelector('.region-empty');
    const districtEmptyMessage = districtOptions.querySelector('.region-empty');
    const clear = combobox.querySelector('.region-clear');
    const districtClear = districtCombobox.querySelector('.region-clear');
    const toggle = combobox.querySelector('.region-toggle');
    const districtToggle = districtCombobox.querySelector('.region-toggle');
    const resolveRegion = value => Object.keys(regions).find(
        item => item.toLocaleLowerCase('id') === value.trim().toLocaleLowerCase('id')
    );

    const syncRegionActions = () => {
        clear.hidden = regency.value.trim() === '';
        combobox.classList.toggle('has-value', !clear.hidden);
    };
    const syncDistrictActions = () => {
        districtClear.hidden = district.value.trim() === '';
        districtCombobox.classList.toggle('has-value', !districtClear.hidden);
    };
    const openDistrictOptions = () => {
        if (district.disabled) return;
        districtOptions.hidden = false;
        district.setAttribute('aria-expanded', 'true');
    };
    const closeDistrictOptions = () => {
        districtOptions.hidden = true;
        district.setAttribute('aria-expanded', 'false');
    };
    const filterDistrictOptions = () => {
        const query = district.value.trim().toLocaleLowerCase('id');
        const buttons = [...districtOptions.querySelectorAll('[data-value]')];
        let visible = 0;
        buttons.forEach(button => {
            const match = button.dataset.value.toLocaleLowerCase('id').includes(query);
            button.hidden = !match;
            if (match) visible++;
        });
        districtEmptyMessage.hidden = visible !== 0;
        openDistrictOptions();
        syncDistrictActions();
    };
    const chooseDistrict = value => {
        district.value = value;
        district.setCustomValidity('');
        syncDistrictActions();
        district.focus({ preventScroll: true });
        closeDistrictOptions();
    };
    const bindDistrictOption = (button, index, buttons) => {
        button.addEventListener('click', () => chooseDistrict(button.dataset.value));
        button.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                buttons.slice(index + 1).find(item => !item.hidden)?.focus();
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                [...buttons.slice(0, index)].reverse().find(item => !item.hidden)?.focus();
            }
            if (event.key === 'Escape') {
                closeDistrictOptions();
                district.focus();
            }
        });
    };
    const populateDistricts = () => {
        const selected = district.dataset.oldValue || district.value;
        const canonicalRegion = resolveRegion(regency.value);
        const items = regions[canonicalRegion] || [];
        if (canonicalRegion) regency.value = canonicalRegion;
        districtOptions.querySelectorAll('[data-value]').forEach(button => button.remove());
        const buttons = items.map(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('role', 'option');
            button.dataset.value = item;
            button.textContent = item;
            districtOptions.insertBefore(button, districtEmptyMessage);
            return button;
        });
        buttons.forEach((button, index) => bindDistrictOption(button, index, buttons));
        district.value = items.includes(selected) ? selected : '';
        district.disabled = !items.length;
        districtToggle.disabled = !items.length;
        district.placeholder = items.length ? 'Cari Kecamatan' : 'Pilih Kabupaten/Kota dahulu';
        district.dataset.oldValue = '';
        districtEmptyMessage.hidden = true;
        regency.setCustomValidity(regency.value && !canonicalRegion ? 'Pilih Kabupaten/Kota dari daftar.' : '');
        syncRegionActions();
        syncDistrictActions();
        closeDistrictOptions();
    };
    const openOptions = () => {
        options.hidden = false;
        regency.setAttribute('aria-expanded', 'true');
    };
    const closeOptions = () => {
        options.hidden = true;
        regency.setAttribute('aria-expanded', 'false');
    };
    const filterOptions = () => {
        const query = regency.value.trim().toLocaleLowerCase('id');
        let visible = 0;
        optionButtons.forEach(button => {
            const match = button.dataset.value.toLocaleLowerCase('id').includes(query);
            button.hidden = !match;
            if (match) visible++;
        });
        emptyMessage.hidden = visible !== 0;
        openOptions();
    };
    const chooseRegion = value => {
        regency.value = value;
        regency.setCustomValidity('');
        district.dataset.oldValue = '';
        populateDistricts();
        closeOptions();
        district.focus();
        filterDistrictOptions();
    };

    regency.addEventListener('focus', filterOptions);
    regency.addEventListener('input', () => {
        district.dataset.oldValue = '';
        populateDistricts();
        filterOptions();
    });
    regency.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeOptions();
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            openOptions();
            optionButtons.find(button => !button.hidden)?.focus();
        }
    });
    toggle.addEventListener('click', () => options.hidden ? filterOptions() : closeOptions());
    district.addEventListener('focus', filterDistrictOptions);
    district.addEventListener('input', () => {
        const items = regions[resolveRegion(regency.value)] || [];
        const canonicalDistrict = items.find(item => item.toLocaleLowerCase('id') === district.value.trim().toLocaleLowerCase('id'));
        if (canonicalDistrict) district.value = canonicalDistrict;
        district.setCustomValidity(district.value && !canonicalDistrict ? 'Pilih Kecamatan dari daftar.' : '');
        filterDistrictOptions();
    });
    district.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeDistrictOptions();
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            openDistrictOptions();
            [...districtOptions.querySelectorAll('[data-value]')].find(button => !button.hidden)?.focus();
        }
    });
    districtToggle.addEventListener('click', () => districtOptions.hidden ? filterDistrictOptions() : closeDistrictOptions());
    districtClear.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        district.value = '';
        district.setCustomValidity('');
        syncDistrictActions();
        filterDistrictOptions();
        district.focus();
    });
    clear.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        regency.value = '';
        regency.setCustomValidity('');
        district.dataset.oldValue = '';
        populateDistricts();
        optionButtons.forEach(button => button.hidden = false);
        emptyMessage.hidden = true;
        openOptions();
        regency.focus();
    });
    optionButtons.forEach((button, index) => {
        button.addEventListener('click', () => chooseRegion(button.dataset.value));
        button.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                optionButtons.slice(index + 1).find(item => !item.hidden)?.focus();
            }
            if (event.key === 'ArrowUp') {
                event.preventDefault();
                [...optionButtons.slice(0, index)].reverse().find(item => !item.hidden)?.focus();
            }
            if (event.key === 'Escape') {
                closeOptions();
                regency.focus();
            }
        });
    });
    document.addEventListener('click', event => {
        if (!combobox.contains(event.target)) closeOptions();
        if (!districtCombobox.contains(event.target)) closeDistrictOptions();
    });
    populateDistricts();
    syncRegionActions();

    const email = document.querySelector('#email'), emailAvailability = document.querySelector('#email-availability');
    let emailStatus = 'idle', emailTimer = null, emailRequest = 0;
    const checkEmail = () => {
        clearTimeout(emailTimer);
        email.setCustomValidity('');
        emailAvailability.classList.remove('valid', 'invalid');
        if (!email.value.trim()) {
            emailStatus = 'idle';
            emailAvailability.textContent = 'Digunakan untuk reset password akun Owner.';
            validate();
            return;
        }
        if (!email.validity.valid) {
            emailStatus = 'invalid';
            emailAvailability.classList.add('invalid');
            emailAvailability.textContent = 'Masukkan alamat email yang valid.';
            validate();
            return;
        }
        emailStatus = 'checking';
        emailAvailability.textContent = 'Memeriksa ketersediaan email…';
        validate();
        const requestId = ++emailRequest;
        emailTimer = setTimeout(async () => {
            try {
                const response = await fetch(@json(route('register.email.check')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('.register-form input[name="_token"]').value,
                    },
                    body: JSON.stringify({ email: email.value.trim() }),
                });
                if (requestId !== emailRequest) return;
                if (!response.ok) throw new Error('Pengecekan email gagal.');
                const result = await response.json();
                emailStatus = result.available ? 'available' : 'taken';
                email.setCustomValidity(result.available ? '' : result.message);
                emailAvailability.classList.toggle('valid', result.available);
                emailAvailability.classList.toggle('invalid', !result.available);
                emailAvailability.textContent = `${result.available ? '✓ ' : ''}${result.message}`;
            } catch (error) {
                if (requestId !== emailRequest) return;
                emailStatus = 'unavailable';
                email.setCustomValidity('');
                emailAvailability.textContent = 'Email akan diperiksa kembali saat pendaftaran.';
            }
            validate();
        }, 500);
    };

    const sfCode = document.querySelector('#sf_code');
    const sfCodeAvailability = document.querySelector('#sf-code-availability');
    let sfCodeStatus = 'idle', sfCodeTimer = null, sfCodeRequest = 0;
    const p = document.querySelector('#password'), c = document.querySelector('#password_confirmation');
    const m = document.querySelector('#password-match'), r = document.querySelector('#password-requirements');
    const t = document.querySelector('#terms'), s = document.querySelector('#register-submit');
    const validate = () => {
        const started = p.value !== '';
        const secure = p.value.length >= 8 && /[a-z]/.test(p.value) && /[A-Z]/.test(p.value) && /\d/.test(p.value) && /[^A-Za-z0-9]/.test(p.value);
        r.classList.toggle('valid', secure);
        r.classList.toggle('invalid', started && !secure);
        r.textContent = secure ? '✓ Kata sandi memenuhi standar keamanan.' : (started ? 'Kata sandi belum memenuhi semua syarat.' : 'Minimal 8 karakter, huruf besar-kecil, angka, dan simbol.');
        const same = c.value !== '' && p.value === c.value;
        c.setCustomValidity(c.value !== '' && !same ? 'Kata sandi belum sama.' : '');
        m.classList.toggle('valid', same);
        m.classList.toggle('invalid', c.value !== '' && !same);
        m.textContent = c.value === '' ? (started ? 'Ulangi kata sandi untuk memastikan sudah sama.' : 'Masukkan ulang kata sandi.') : (same ? '✓ Kata sandi sudah sama.' : 'Kata sandi belum sama.');
        s.disabled = !(secure && same && t.checked
            && !['checking', 'taken', 'invalid'].includes(emailStatus)
            && !['checking', 'missing'].includes(sfCodeStatus));
    };
    email.addEventListener('input', checkEmail);
    email.addEventListener('blur', checkEmail);
    p.addEventListener('input', validate);
    c.addEventListener('input', validate);
    t.addEventListener('change', validate);
    validate();
    if (email.value.trim()) checkEmail();

    const checkSfCode = () => {
        clearTimeout(sfCodeTimer);
        sfCode.value = sfCode.value.toUpperCase().replace(/\s+/g, '');
        sfCode.setCustomValidity('');
        sfCodeAvailability.classList.remove('valid', 'invalid');
        if (!sfCode.value.trim()) {
            sfCodeStatus = 'idle';
            sfCodeAvailability.textContent = 'Outlet akan masuk ke akun SF ini untuk diperiksa dan disetujui.';
            validate();
            return;
        }
        sfCodeStatus = 'checking';
        sfCodeAvailability.textContent = 'Memeriksa SF Code…';
        validate();
        const requestId = ++sfCodeRequest;
        sfCodeTimer = setTimeout(async () => {
            try {
                const response = await fetch(@json(route('register.sf-code.check')), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('.register-form input[name="_token"]').value,
                    },
                    body: JSON.stringify({ sf_code: sfCode.value.trim() }),
                });
                if (requestId !== sfCodeRequest) return;
                if (!response.ok) throw new Error('Pengecekan SF Code gagal.');
                const result = await response.json();
                sfCodeStatus = result.found ? 'found' : 'missing';
                sfCode.value = result.sf_code;
                sfCode.setCustomValidity(result.found ? '' : result.message);
                sfCodeAvailability.classList.toggle('valid', result.found);
                sfCodeAvailability.classList.toggle('invalid', !result.found);
                sfCodeAvailability.textContent = `${result.found ? '✓ ' : '⚠ '}${result.message}`;
            } catch (error) {
                if (requestId !== sfCodeRequest) return;
                sfCodeStatus = 'unavailable';
                sfCode.setCustomValidity('');
                sfCodeAvailability.textContent = 'SF Code akan diperiksa kembali saat pendaftaran.';
            }
            validate();
        }, 450);
    };
    sfCode.addEventListener('input', checkSfCode);
    sfCode.addEventListener('blur', checkSfCode);
    if (sfCode.value.trim()) checkSfCode();
});
</script>
@endsection

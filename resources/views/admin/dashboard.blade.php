@extends('layouts.app')
@section('title','Super Admin — Docan')
@section('body-class','pos-body admin-body')
@section('content')
<div class="admin-shell pro-admin">
    <aside class="admin-sidebar">
        <div class="admin-sidebar-brand"><span>D</span><div><b>Docan</b><small>Super Admin</small></div></div>
        <nav>
            <a href="{{ route('admin.dashboard') }}" class="{{ $page==='dashboard'?'active':'' }}"><span>⌂</span>Dashboard</a>
            <a href="{{ route('admin.outlets') }}" class="{{ $page==='outlets'?'active':'' }}"><span>▦</span>Outlet & User</a>
            <a href="{{ route('admin.transactions') }}" class="{{ $page==='transactions'?'active':'' }}"><span>↔</span>Transaksi</a>
            <a href="{{ route('admin.denominations') }}" class="{{ $page==='denominations'?'active':'' }}"><span>◫</span>Master Produk</a>
        </nav>
        <div class="sidebar-account"><span>{{ strtoupper(substr(auth()->user()->name,0,1)) }}</span><div><b>{{ auth()->user()->name }}</b><small>{{ auth()->user()->email }}</small></div><form method="POST" action="{{ route('logout') }}">@csrf<button title="Keluar">↪</button></form></div>
    </aside>
    <div class="admin-workspace">
        <header class="admin-header"><div><b>Control Center</b><small>{{ now()->translatedFormat('l, d F Y') }}</small></div><div class="admin-header-actions"><span class="live-status">● Sistem aktif</span><form class="admin-mobile-logout" method="POST" action="{{ route('logout') }}">@csrf<button type="submit" aria-label="Keluar dari akun"><span>↪</span> Keluar</button></form></div></header>
        <main class="admin-main">
            @if(session('success'))<div class="toast success">✓ {{ session('success') }}</div>@endif
            @php($adminError=$errors->createOutlet->first() ?: $errors->editOutlet->first() ?: $errors->first())
            @if($adminError)<div class="alert error">{{ $adminError }}</div>@endif

            @if($page==='dashboard')<section id="dashboard" class="admin-dashboard">
                <div class="page-title"><div><span class="eyebrow green">ANALISIS BISNIS</span><h1>Selamat datang, Admin</h1><p>Pantau performa seluruh jaringan outlet Docan.</p></div></div>
                <div class="admin-kpi-grid">
                    <article><span>Omset hari ini</span><strong>Rp {{ number_format($todayTurnover,0,',','.') }}</strong><small>{{ number_format($todayCount) }} transaksi · laba Rp {{ number_format($todayProfit,0,',','.') }}</small></article>
                    <article><span>Omset bulan ini</span><strong>Rp {{ number_format($monthTurnover,0,',','.') }}</strong><small>{{ number_format($monthCount) }} transaksi · laba Rp {{ number_format($monthProfit,0,',','.') }}</small></article>
                    <article><span>Jaringan outlet</span><strong>{{ number_format($outlets->count()) }}</strong><small>{{ number_format($outletUsers) }} akun pengguna aktif</small></article>
                </div>
                @php($adminMax=max(1,$trend->max('turnover')))
                <div class="admin-analysis-grid">
                    <article class="admin-chart-card"><div class="analysis-head"><div><h2>Tren 7 hari</h2><p>Pergerakan omset seluruh outlet</p></div><b>Rp {{ number_format($trend->sum('turnover'),0,',','.') }}</b></div><div class="admin-bars">@foreach($trend as $day)<div><span>{{ $day['turnover']?number_format($day['turnover']/1000,0).'K':'' }}</span><i><b style="height:{{ max(4,($day['turnover']/$adminMax)*100) }}%"></b></i><strong>{{ $day['label'] }}</strong><small>{{ $day['count'] }} trx</small></div>@endforeach</div></article>
                    <article class="top-outlet-card"><div class="analysis-head"><div><h2>Outlet teratas</h2><p>Berdasarkan total omset</p></div></div>@forelse($topOutlets as $index=>$item)<div class="top-outlet-row"><span>{{ $index+1 }}</span><p><b>{{ $item->name }}</b><small>{{ number_format($item->transaction_count) }} transaksi</small></p><strong>Rp {{ number_format($item->turnover,0,',','.') }}</strong></div>@empty<p class="empty-state">Belum ada transaksi outlet.</p>@endforelse</article>
                </div>
                @php($shareColors=['#ed1b2f','#15a9e5','#1947ba','#292532','#f5b800','#ec168c','#7a2988'])
                @php($donut=function($items)use($shareColors){$total=$items->sum('value');if(!$total)return 'conic-gradient(#eeeaf0 0 100%)';$at=0;$parts=[];foreach($items as $index=>$item){$start=$at;$at+=($item->value/$total)*100;$parts[]=$shareColors[$index].' '.$start.'% '.$at.'%';}return 'conic-gradient('.implode(',',$parts).')';})
                <article class="operator-analysis"><div class="analysis-head"><div><h2>Share penjualan operator</h2><p>Perbandingan antaroperator bulan ini</p></div></div><div class="share-grid"><section><div class="donut" style="--donut:{{ $donut($starterShare) }}"><span><b>{{ number_format($starterShare->sum('value')) }}</b><small>kartu</small></span></div><h3>Sales share</h3><p>Khusus penjualan Kartu Paket/perdana</p></section><section><div class="donut" style="--donut:{{ $donut($rechargeShare) }}"><span><b>Rp {{ number_format($rechargeShare->sum('value')/1000,0) }}K</b><small>recharge</small></span></div><h3>Recharge share</h3><p>Nilai transaksi Pulsa Reguler & Pulsa Data</p></section><div class="share-legend">@foreach($starterShare as $index=>$item)<div><i style="background:{{ $shareColors[$index] }}"></i><b>{{ $item->operator }}</b><span>{{ $starterShare->sum('value')?number_format($item->value/$starterShare->sum('value')*100,1):0 }}% kartu</span><small>{{ $rechargeShare->sum('value')?number_format($rechargeShare[$index]->value/$rechargeShare->sum('value')*100,1):0 }}% recharge · Rp {{ number_format($rechargeShare[$index]->value,0,',','.') }}</small></div>@endforeach</div></div></article>
                <article class="voucher-analysis"><div class="analysis-head"><div><h2>Penjualan voucher per denom</h2><p>Jumlah unit terjual berdasarkan operator, kuota, dan validity · bulan ini</p></div><span class="analysis-note">{{ $voucherComparison->sum('sales') }} unit</span></div><div class="voucher-table-wrap denom-matrix"><table><thead><tr><th>Operator</th><th>Denom</th>@foreach($validityHeaders as $day)<th>{{ $day }}D</th>@endforeach<th>Total</th></tr></thead><tbody>@forelse($voucherComparison->groupBy(fn($item)=>$item->provider.'|'.$item->quota_gb) as $key=>$rows)@php([$matrixOperator,$matrixQuota]=explode('|',$key))<tr><td><b>{{ $matrixOperator }}</b></td><td>{{ number_format($matrixQuota,(float)$matrixQuota==(int)$matrixQuota?0:1,',','.') }} GB</td>@foreach($validityHeaders as $day)@php($sale=(int)optional($rows->firstWhere('validity_days',$day))->sales)<td class="{{ $sale?'has-sale':'' }}">{{ $sale?:'–' }}</td>@endforeach<td><strong>{{ number_format($rows->sum('sales')) }}</strong></td></tr>@empty<tr><td colspan="10" class="empty-state">Belum ada penjualan voucher bulan ini.</td></tr>@endforelse</tbody></table></div></article>
            </section>

            @endif @if($page==='outlets' && session('credentials'))<div class="credential-card"><div><span>AKUN SIAP DIBERIKAN</span><b>ID Outlet: {{ session('credentials.login_id') }}</b><small>Password default: <strong>{{ session('credentials.password') }}</strong></small></div><p>Salin data ini sekarang. Password tidak akan ditampilkan lagi setelah halaman ditutup.</p></div>@endif

            @if($page==='outlets')<section class="admin-panel outlet-management" id="outlets">
                <div class="admin-panel-head"><div><h2>Outlet & akun pengguna</h2><p>Profil outlet, akun Owner, dan akun Frontliner dalam satu direktori.</p></div><div class="admin-head-actions"><a href="{{ route('admin.outlets.export', request()->only('outlet_search')) }}">↓ Download outlet & user</a><span class="admin-count">{{ $outlets->count() }} outlet</span></div></div>
                <div @class(['admin-mail-status','ready'=>$mailConfigured])><div><b>{{ $mailConfigured ? '✓ Email reset siap diuji' : '⚠ Email belum dikonfigurasi' }}</b><span>{{ $mailConfigured ? 'Mailer '.strtoupper(config('mail.default')).' aktif sebagai '.config('mail.from.address').'.' : 'Isi MAIL_MAILER dan kredensial SMTP di .env, lalu restart container.' }}</span></div><form method="POST" action="{{ route('admin.mail.test') }}">@csrf<input type="email" name="email" value="{{ auth()->user()->email }}" placeholder="Email tujuan" required><button>Kirim email tes</button></form></div>
                @php($creatingOutlet=old('_form_context')==='create-outlet')
                <datalist id="admin-regencies">@foreach(array_keys($outletRegions) as $regency)<option value="{{ $regency }}"></option>@endforeach</datalist>
                <div class="outlet-onboarding">
                    <form method="POST" action="{{ route('admin.outlets.store') }}" class="quick-outlet-form outlet-profile-form" data-region-form>@csrf
                        <input type="hidden" name="_form_context" value="create-outlet">
                        <div class="profile-form-heading"><h3>Tambah manual</h3><p>Lengkapi profil outlet seperti pada pendaftaran outlet baru.</p></div>
                        <label>Nama outlet<input name="name" value="{{ $creatingOutlet ? old('name') : '' }}" placeholder="Outlet Antasari" required></label>
                        <label>Nama Owner<input name="owner_name" value="{{ $creatingOutlet ? old('owner_name') : '' }}" placeholder="Owner Antasari" required></label>
                        <label>ID Outlet<input name="login_id" value="{{ $creatingOutlet ? old('login_id') : '' }}" placeholder="ATS-001" maxlength="40" required></label>
                        <label>Nomor RS<input name="phone" value="{{ $creatingOutlet ? old('phone') : '' }}" inputmode="numeric" pattern="[0-9]{6,20}" placeholder="081234567890" required></label>
                        <label>Kabupaten/Kota<input name="regency" data-regency list="admin-regencies" value="{{ $creatingOutlet ? old('regency') : '' }}" placeholder="Ketik nama Kabupaten/Kota" autocomplete="off" required></label>
                        <label>Kecamatan<input name="district" data-district list="admin-create-districts" data-selected="{{ $creatingOutlet ? old('district') : '' }}" value="{{ $creatingOutlet ? old('district') : '' }}" placeholder="Pilih Kabupaten/Kota dahulu" autocomplete="off" required disabled><datalist id="admin-create-districts"></datalist></label>
                        <label>Email Owner<input type="email" name="email" value="{{ $creatingOutlet ? old('email') : '' }}" placeholder="owner@email.com" required></label>
                        <label>Password awal<input type="password" name="password" value="Docan123!" minlength="8" required></label>
                        <label>Ulangi password<input type="password" name="password_confirmation" value="Docan123!" minlength="8" required></label>
                        <button>＋ Buat outlet</button>
                    </form>
                    <div class="bulk-import"><div><h3>Upload CSV</h3><p>Kolom CSV mengikuti seluruh profil pada form manual.</p><a href="{{ route('admin.outlets.example') }}">Download contoh CSV</a></div><form method="POST" enctype="multipart/form-data" action="{{ route('admin.outlets.import') }}">@csrf<label><input type="file" name="csv" accept=".csv,text/csv" required><span>Pilih CSV</span></label><button>Upload</button></form></div>
                </div>
                @if(session('import_errors'))<div class="import-result"><b>Beberapa baris tidak diproses:</b>@foreach(session('import_errors') as $message)<span>{{ $message }}</span>@endforeach</div>@endif
                <form class="outlet-search-form" method="GET" action="{{ route('admin.outlets') }}"><input type="search" name="outlet_search" value="{{ request('outlet_search') }}" placeholder="Cari ID, outlet, wilayah, Owner, Frontliner, email, atau Nomor RS"><button type="submit">Cari outlet</button>@if(request()->filled('outlet_search'))<a href="{{ route('admin.outlets') }}">Reset</a>@endif</form>
                <div class="admin-table-wrap outlet-directory"><table><thead><tr><th>ID Outlet</th><th>Nama outlet</th><th>Nomor RS</th><th>Kabupaten</th><th>Kecamatan</th><th>Akun Owner</th><th>Akun Frontliner</th><th>Email</th><th>Tanggal Dibuat</th><th>Aksi</th></tr></thead><tbody>
                    @forelse($outletDirectory as $outlet)
                        @php($owner=$outlet->users->first(fn($user)=>in_array($user->role,['owner','outlet'],true)))
                        @php($frontliners=$outlet->users->where('role','frontliner'))
                        @php($editingThis=old('_form_context')==='edit-outlet-'.$outlet->id)
                        <tr>
                            <td><span class="outlet-id-badge">{{ $outlet->login_id }}</span></td>
                            <td><b>{{ $outlet->name }}</b></td>
                            <td>{{ $owner?->phone ?: '—' }}</td>
                            <td>{{ $outlet->regency ?: 'Belum diatur' }}</td>
                            <td>{{ $outlet->district ?: 'Belum diatur' }}</td>
                            <td><b>{{ $owner?->name ?: 'Belum ada' }}</b><small>{{ $owner?->login_id }}</small></td>
                            <td>@forelse($frontliners as $frontliner)<span class="user-line"><b>{{ $frontliner->name }}</b><small>{{ $frontliner->login_id }}</small></span>@empty<span>Belum ada</span>@endforelse</td>
                            <td>{{ $owner?->email ?: '—' }}</td>
                            <td>{{ $outlet->created_at?->format('d/m/Y H:i') }}</td>
                            <td><details class="outlet-inline-editor" @if($editingThis && $errors->editOutlet->any()) open @endif><summary>Edit data</summary><form method="POST" action="{{ route('admin.outlets.update',$outlet) }}" data-region-form>@csrf @method('PUT')<input type="hidden" name="_form_context" value="edit-outlet-{{ $outlet->id }}"><div class="profile-form-heading"><div><h3>Edit profil outlet</h3><p>Perbarui data profil tanpa mengubah transaksi maupun stok outlet.</p></div><button type="button" class="outlet-editor-close" data-close-outlet-editor aria-label="Tutup edit profil">×</button></div><label>ID Outlet<input value="{{ $outlet->login_id }}" readonly></label><label>Nama outlet<input name="name" value="{{ $editingThis ? old('name') : $outlet->name }}" required></label><label>Nama Owner<input name="owner_name" value="{{ $editingThis ? old('owner_name') : $owner?->name }}" required></label><label>Email Owner<input type="email" name="email" value="{{ $editingThis ? old('email') : (str_ends_with($owner?->email ?? '','@outlet.docan.local') ? '' : $owner?->email) }}" required></label><label>Nomor RS<input name="phone" inputmode="numeric" pattern="[0-9]{6,20}" value="{{ $editingThis ? old('phone') : $owner?->phone }}" required></label><label>Kabupaten/Kota<input name="regency" data-regency list="admin-regencies" value="{{ $editingThis ? old('regency') : $outlet->regency }}" placeholder="Ketik nama Kabupaten/Kota" autocomplete="off" required></label><label>Kecamatan<input name="district" data-district list="admin-districts-{{ $outlet->id }}" data-selected="{{ $editingThis ? old('district') : $outlet->district }}" value="{{ $editingThis ? old('district') : $outlet->district }}" placeholder="Pilih Kabupaten/Kota dahulu" autocomplete="off" required disabled><datalist id="admin-districts-{{ $outlet->id }}"></datalist></label><button>Simpan perubahan</button></form></details></td>
                        </tr>
                    @empty<tr><td colspan="10" class="empty-state">Outlet tidak ditemukan.</td></tr>@endforelse
                </tbody></table></div>@if($outletDirectory->hasPages())<nav class="pager"><a class="{{ $outletDirectory->onFirstPage()?'disabled':'' }}" href="{{ $outletDirectory->previousPageUrl()?:'#' }}">← Sebelumnya</a><span>Halaman {{ $outletDirectory->currentPage() }} dari {{ $outletDirectory->lastPage() }}</span><a class="{{ $outletDirectory->hasMorePages()?'':'disabled' }}" href="{{ $outletDirectory->nextPageUrl()?:'#' }}">Berikutnya →</a></nav>@endif
            </section>

            @endif @if($page==='transactions')<section class="admin-panel" id="transactions">
                <div class="admin-panel-head"><div><h2>Semua transaksi</h2><p>Filter dan unduh rekap dalam format CSV.</p></div><a href="{{ route('admin.export',request()->query()) }}">↓ Download CSV</a></div>
                <form class="admin-filters range-filters"><label>Pilih outlet<select name="outlet"><option value="">Semua outlet</option>@foreach($outlets as $outlet)<option value="{{ $outlet->id }}" @selected(request('outlet')==$outlet->id)>{{ $outlet->name }}</option>@endforeach</select></label><label>Dari tanggal<input type="date" name="date_from" value="{{ request('date_from') }}" max="{{ request('date_to') }}"></label><label>Sampai tanggal<input type="date" name="date_to" value="{{ request('date_to') }}" min="{{ request('date_from') }}"></label><button>Terapkan</button></form>
                <div class="admin-table-wrap"><table><thead><tr><th>Waktu</th><th>Outlet / Kasir</th><th>Produk</th><th>Qty</th><th>Jual</th><th>Laba</th></tr></thead><tbody>@forelse($transactions as $transaction)<tr><td>{{ $transaction->created_at->format('d/m/Y H:i') }}</td><td><b>{{ $transaction->user?->outlet?->name ?? '-' }}</b><small>{{ $transaction->user?->name }}</small></td><td><b>{{ $transaction->provider }}</b><small>{{ $transaction->product?->name ?? $transaction->product_type }}</small></td><td><span class="quantity-badge">{{ number_format($transaction->quantity ?? 1) }}</span></td><td>Rp {{ number_format($transaction->price,0,',','.') }}</td><td class="green-text">Rp {{ number_format($transaction->profit,0,',','.') }}</td></tr>@empty<tr><td colspan="6" class="empty-state">Belum ada transaksi.</td></tr>@endforelse</tbody></table></div>
                @if($transactions->hasPages())<nav class="pager"><a class="{{ $transactions->onFirstPage()?'disabled':'' }}" href="{{ $transactions->previousPageUrl()?:'#' }}">← Sebelumnya</a><span>Halaman {{ $transactions->currentPage() }} dari {{ $transactions->lastPage() }}</span><a class="{{ $transactions->hasMorePages()?'':'disabled' }}" href="{{ $transactions->nextPageUrl()?:'#' }}">Berikutnya →</a></nav>@endif
            </section>

            @endif @if($page==='denominations')<section class="admin-panel" id="denominations">
                <div class="admin-panel-head"><div><h2>Master produk outlet</h2><p>Semua produk yang diatur outlet, termasuk modal, harga jual, dan stok.</p></div><div class="admin-head-actions"><a href="{{ route('admin.products.export',request()->only('product_outlet','product_search')) }}">↓ Download CSV</a><span class="admin-count">{{ $outlets->count() }} outlet</span></div></div>
                <div class="master-outlet-directory">
                    <div class="master-outlet-heading"><div><h3>Daftar outlet Master Produk</h3><p>Terhubung otomatis dengan data Outlet & User.</p></div><span>{{ $outlets->count() }} outlet unik</span></div>
                    <div class="master-outlet-list">
                        @forelse($outlets as $outlet)
                            <a href="{{ route('admin.denominations',['product_outlet'=>$outlet->id]) }}" @class(['active'=>(string)request('product_outlet')===(string)$outlet->id])>
                                <span><b>{{ $outlet->name }}</b><small>{{ $outlet->login_id }}</small></span>
                                <strong>{{ number_format($outlet->products_count) }} produk</strong>
                            </a>
                        @empty
                            <p class="empty-state">Belum ada outlet.</p>
                        @endforelse
                    </div>
                </div>
                <form class="catalog-filters"><select name="product_outlet"><option value="">Semua outlet</option>@foreach($outlets as $outlet)<option value="{{ $outlet->id }}" @selected(request('product_outlet')==$outlet->id)>{{ $outlet->name }}</option>@endforeach</select><input name="product_search" value="{{ request('product_search') }}" placeholder="Cari nama produk atau operator"><button>Tampilkan</button></form>
                <div class="admin-table-wrap product-master-table"><table><thead><tr><th>Outlet</th><th>Operator</th><th>Nama produk</th><th>Kategori</th><th>Modal</th><th>Harga jual</th><th>Stok</th></tr></thead><tbody>@forelse($catalogProducts as $product)<tr><td><b>{{ $product->outlet?->name }}</b><small>{{ $product->outlet?->login_id }}</small></td><td><b>{{ $product->operator }}</b></td><td>{{ $product->name }}</td><td>{{ $product->category }}</td><td>Rp {{ number_format($product->cost_price,0,',','.') }}</td><td><b>Rp {{ number_format($product->selling_price,0,',','.') }}</b><small>Untung Rp {{ number_format($product->profit,0,',','.') }}</small></td><td><span class="quantity-badge">{{ number_format($product->stock) }}</span></td></tr>@empty<tr><td colspan="7" class="empty-state">Produk outlet tidak ditemukan.</td></tr>@endforelse</tbody></table></div>
                @if($catalogProducts->hasPages())<nav class="pager"><a class="{{ $catalogProducts->onFirstPage()?'disabled':'' }}" href="{{ $catalogProducts->previousPageUrl()?:'#' }}">← Sebelumnya</a><span>Halaman {{ $catalogProducts->currentPage() }} dari {{ $catalogProducts->lastPage() }}</span><a class="{{ $catalogProducts->hasMorePages()?'':'disabled' }}" href="{{ $catalogProducts->nextPageUrl()?:'#' }}">Berikutnya →</a></nav>@endif
                <div class="quick-denom-section"><div class="admin-panel-head"><div><h2>Nominal cepat</h2><p>Pilihan tanpa stok untuk pulsa, e-wallet, PLN, BRILink, dan PPOB.</p></div></div>
                <form class="denom-add" method="POST" action="{{ route('admin.denominations.store') }}">@csrf<select name="operator">@foreach($operators as $operator)<option>{{ $operator }}</option>@endforeach</select><select name="category">@foreach($categories as $category)<option>{{ $category }}</option>@endforeach</select><input type="number" name="nominal" min="1000" step="1000" placeholder="Nominal" required><label><input type="checkbox" name="is_active" value="1" checked> Aktif</label><button>＋ Tambah denom</button></form>
                <div class="denom-list">@foreach($denominations as $denom)<form method="POST" action="{{ route('admin.denominations.update',$denom) }}">@csrf @method('PUT')<select name="operator">@foreach($operators as $operator)<option @selected($denom->operator===$operator)>{{ $operator }}</option>@endforeach</select><select name="category">@foreach($categories as $category)<option @selected($denom->category===$category)>{{ $category }}</option>@endforeach</select><input type="number" name="nominal" value="{{ $denom->nominal }}"><label><input type="checkbox" name="is_active" value="1" @checked($denom->is_active)> Aktif</label><button>Simpan</button><button class="delete" formmethod="POST" formaction="{{ route('admin.denominations.destroy',$denom) }}" name="_method" value="DELETE">Hapus</button></form>@endforeach</div></div>
            </section>@endif
        </main>
    </div>
</div>
@if($page==='outlets')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const regions = @json($outletRegions);

    document.querySelectorAll('[data-region-form]').forEach(form => {
        const regency = form.querySelector('[data-regency]');
        const district = form.querySelector('[data-district]');
        if (!regency || !district) return;
        const districtOptions = document.getElementById(district.getAttribute('list'));

        const populateDistricts = () => {
            const city = regency.value.trim().toLocaleUpperCase('id');
            const selected = (district.dataset.selected || district.value).trim().toLocaleUpperCase('id');
            const districts = regions[city] || [];
            regency.setCustomValidity(regency.value && !regions[city] ? 'Pilih Kabupaten/Kota dari daftar.' : '');
            if (regions[city]) regency.value = city;
            districtOptions.replaceChildren(...districts.map(name => new Option(name, name)));
            district.disabled = districts.length === 0;
            district.placeholder = districts.length ? 'Ketik nama Kecamatan' : 'Pilih Kabupaten/Kota dahulu';
            district.value = districts.includes(selected) ? selected : '';
            district.setCustomValidity('');
            district.dataset.selected = '';
        };

        const validateDistrict = () => {
            const districts = regions[regency.value.trim().toLocaleUpperCase('id')] || [];
            const value = district.value.trim().toLocaleUpperCase('id');
            district.setCustomValidity(value && !districts.includes(value) ? 'Pilih Kecamatan dari daftar.' : '');
            if (districts.includes(value)) district.value = value;
        };

        regency.addEventListener('input', () => {
            district.dataset.selected = '';
            populateDistricts();
        });
        regency.addEventListener('change', populateDistricts);
        district.addEventListener('input', validateDistrict);
        district.addEventListener('change', validateDistrict);
        populateDistricts();
    });

    document.querySelectorAll('[data-close-outlet-editor]').forEach(button => {
        button.addEventListener('click', () => button.closest('details')?.removeAttribute('open'));
    });
});
</script>
@endif
@endsection

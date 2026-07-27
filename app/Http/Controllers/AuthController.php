<?php

namespace App\Http\Controllers;

use App\Models\Outlet;
use App\Models\User;
use App\Services\StarterCatalogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function showRegister()
    {
        return view('auth.register', ['outletRegions' => config('outlet_regions', [])]);
    }

    public function showForgotPassword()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        PasswordBroker::sendResetLink(['email' => strtolower(trim((string) $request->email))]);

        return back()->with('status', 'Jika email terdaftar, tautan reset password sudah dikirim. Silakan cek kotak masuk dan folder spam.');
    }

    public function showResetPassword(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->string('email')->toString()]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
        ], [
            'password.confirmed' => 'Ulangi password harus sama dengan password baru.',
            'password.min' => 'Password minimal 8 karakter.',
            'password.mixed' => 'Password harus berisi huruf besar dan huruf kecil.',
            'password.numbers' => 'Password harus berisi angka.',
            'password.symbols' => 'Password harus berisi simbol.',
        ]);
        $status = PasswordBroker::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        });

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'Tautan reset tidak valid atau sudah kedaluwarsa.'])->withInput($request->only('email'));
        }

        return redirect()->route('login')->with('status', 'Password berhasil diperbarui. Silakan masuk menggunakan password baru.');
    }

    public function register(Request $request, StarterCatalogService $catalog)
    {
        $request->merge(['login_id' => strtoupper(trim((string) $request->login_id))]);
        $regions = config('outlet_regions', []);
        $data = $request->validate([
            'outlet_name' => ['required', 'string', 'max:120'],
            'owner_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'regency' => ['required', 'string', Rule::in(array_keys($regions))],
            'district' => ['required', 'string', Rule::in($regions[$request->input('regency')] ?? [])],
            'login_id' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9-]+$/', 'unique:outlets,login_id', 'unique:users,login_id'],
            'rs_number' => ['required', 'string', 'max:20', 'regex:/^[0-9]{6,20}$/'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
            'terms' => ['accepted'],
        ], [
            'login_id.regex' => 'User Login hanya boleh berisi huruf, angka, dan tanda hubung.',
            'regency.required' => 'Pilih Kabupaten/Kota outlet.',
            'regency.in' => 'Pilih Kabupaten/Kota yang tersedia pada daftar.',
            'district.required' => 'Pilih Kecamatan outlet.',
            'district.in' => 'Kecamatan tidak sesuai dengan Kabupaten/Kota yang dipilih.',
            'login_id.unique' => 'User Login sudah digunakan. Silakan pilih User Login lain.',
            'rs_number.regex' => 'Nomor RS hanya boleh berisi 6–20 angka.',
            'password.confirmed' => 'Ulangi kata sandi harus sama dengan kata sandi.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.mixed' => 'Kata sandi harus berisi huruf besar dan huruf kecil.',
            'password.letters' => 'Kata sandi harus berisi huruf.',
            'password.numbers' => 'Kata sandi harus berisi angka.',
            'password.symbols' => 'Kata sandi harus berisi simbol.',
            'terms.accepted' => 'Anda perlu menyetujui Syarat dan Ketentuan serta Kebijakan Privasi.',
        ]);
        $user = DB::transaction(function () use ($data, $catalog) {
            $outlet = Outlet::create(['name' => $data['outlet_name'], 'login_id' => $data['login_id'], 'code' => $data['login_id'], 'regency' => $data['regency'], 'district' => $data['district']]);
            $catalog->apply($outlet);

            return User::create(['outlet_id' => $outlet->id, 'name' => $data['owner_name'], 'email' => strtolower($data['email']), 'login_id' => $data['login_id'], 'phone' => $data['rs_number'], 'password' => $data['password'], 'role' => 'owner', 'terms_accepted_at' => now()]);
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('pos')->with('success', 'Outlet dan akun Owner sudah siap digunakan. Selamat datang di Docan.')->with('success_kind', 'account')->with('prompt_pwa', true);
    }

    public function terms()
    {
        return view('legal.terms');
    }

    public function privacy()
    {
        return view('legal.privacy');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['login_id' => ['required', 'string', 'max:255'], 'password' => ['required']]);
        $identifier = trim($data['login_id']);
        $loggedIn = false;
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $loggedIn = Auth::attempt(['email' => $identifier, 'password' => $data['password']], $request->boolean('remember'));
        } else {
            $user = User::where('login_id', strtoupper($identifier))->whereIn('role', ['owner', 'frontliner', 'outlet'])->first();
            if ($user && Hash::check($data['password'], $user->password)) {
                Auth::login($user, $request->boolean('remember'));
                $loggedIn = true;
            }
        }if (! $loggedIn) {
            return back()->withErrors(['login_id' => 'ID pengguna atau kata sandi belum sesuai.'])->onlyInput('login_id');
        }$request->session()->regenerate();
        $admin = $request->user()->role === 'super_admin';
        $response = redirect()->intended($admin ? route('admin.dashboard') : route('pos'));

        return $admin ? $response : $response->with('prompt_pwa', true);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}

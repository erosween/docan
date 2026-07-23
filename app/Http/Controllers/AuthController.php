<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use App\Models\Outlet;
use App\Models\User;
use App\Services\StarterCatalogService;
class AuthController extends Controller {
 public function show(){return view('auth.login');}
 public function showRegister(){return view('auth.register');}
 public function register(Request $request, StarterCatalogService $catalog){
  $request->merge(['login_id'=>strtoupper(trim((string)$request->login_id))]);
  $data=$request->validate([
   'outlet_name'=>['required','string','max:120'],
   'owner_name'=>['required','string','max:120'],
   'login_id'=>['required','string','max:40','regex:/^[A-Z0-9-]+$/','unique:outlets,login_id','unique:users,login_id'],
   'rs_number'=>['required','string','max:20','regex:/^[0-9]{6,20}$/'],
   'password'=>['required','confirmed',Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
   'terms'=>['accepted'],
  ],[
   'login_id.regex'=>'User Login hanya boleh berisi huruf, angka, dan tanda hubung.',
   'login_id.unique'=>'User Login sudah digunakan. Silakan pilih User Login lain.',
   'rs_number.regex'=>'Nomor RS hanya boleh berisi 6–20 angka.',
   'password.confirmed'=>'Ulangi kata sandi harus sama dengan kata sandi.',
   'password.min'=>'Kata sandi minimal 8 karakter.',
   'password.mixed'=>'Kata sandi harus berisi huruf besar dan huruf kecil.',
   'password.letters'=>'Kata sandi harus berisi huruf.',
   'password.numbers'=>'Kata sandi harus berisi angka.',
   'password.symbols'=>'Kata sandi harus berisi simbol.',
   'terms.accepted'=>'Anda perlu menyetujui Syarat dan Ketentuan serta Kebijakan Privasi.',
  ]);
  $user=DB::transaction(function()use($data,$catalog){
   $outlet=Outlet::create(['name'=>$data['outlet_name'],'login_id'=>$data['login_id'],'code'=>$data['login_id']]);
   $catalog->apply($outlet);
   return User::create(['outlet_id'=>$outlet->id,'name'=>$data['owner_name'],'email'=>strtolower($data['login_id']).'.'.str()->random(8).'@outlet.docan.local','login_id'=>$data['login_id'],'phone'=>$data['rs_number'],'password'=>$data['password'],'role'=>'owner','terms_accepted_at'=>now()]);
  });
  Auth::login($user);$request->session()->regenerate();
  return redirect()->route('pos')->with('success','Outlet dan akun Owner sudah siap digunakan. Selamat datang di Docan.')->with('success_kind','account')->with('prompt_pwa',true);
 }
 public function terms(){return view('legal.terms');}
 public function privacy(){return view('legal.privacy');}
 public function login(Request $request){$data=$request->validate(['login_id'=>['required','string','max:255'],'password'=>['required']]);$identifier=trim($data['login_id']);$loggedIn=false;if(filter_var($identifier,FILTER_VALIDATE_EMAIL))$loggedIn=Auth::attempt(['email'=>$identifier,'password'=>$data['password']],$request->boolean('remember'));else{$user=User::where('login_id',strtoupper($identifier))->whereIn('role',['owner','frontliner','outlet'])->first();if($user&&Hash::check($data['password'],$user->password)){Auth::login($user,$request->boolean('remember'));$loggedIn=true;}}if(!$loggedIn)return back()->withErrors(['login_id'=>'ID pengguna atau kata sandi belum sesuai.'])->onlyInput('login_id');$request->session()->regenerate();$admin=$request->user()->role==='super_admin';$response=redirect()->intended($admin?route('admin.dashboard'):route('pos'));return $admin?$response:$response->with('prompt_pwa',true);}
 public function logout(Request $request){Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect()->route('login');}
}

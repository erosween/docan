<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
class AuthController extends Controller {
 public function show(){return view('auth.login');}
 public function login(Request $request){$data=$request->validate(['login_id'=>['required','string','max:255'],'password'=>['required']]);$identifier=trim($data['login_id']);$loggedIn=false;if(filter_var($identifier,FILTER_VALIDATE_EMAIL))$loggedIn=Auth::attempt(['email'=>$identifier,'password'=>$data['password']],$request->boolean('remember'));else{$user=User::where('login_id',strtoupper($identifier))->whereIn('role',['owner','frontliner','outlet'])->first();if($user&&Hash::check($data['password'],$user->password)){Auth::login($user,$request->boolean('remember'));$loggedIn=true;}}if(!$loggedIn)return back()->withErrors(['login_id'=>'ID pengguna atau kata sandi belum sesuai.'])->onlyInput('login_id');$request->session()->regenerate();$admin=$request->user()->role==='super_admin';$response=redirect()->intended($admin?route('admin.dashboard'):route('pos'));return $admin?$response:$response->with('prompt_pwa',true);}
 public function logout(Request $request){Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();return redirect()->route('login');}
}

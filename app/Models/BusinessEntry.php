<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class BusinessEntry extends Model {protected $fillable=['outlet_id','user_id','contact_id','type','reference','description','amount','entry_date','due_date','status'];protected function casts():array{return ['entry_date'=>'date','due_date'=>'date'];}public function contact(){return $this->belongsTo(BusinessContact::class,'contact_id');}}

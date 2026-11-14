<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class RefundAccount extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['account_number', 'account_fingerprint'];

    protected function casts(): array
    {
        return ['account_number' => 'encrypted', 'verified_at' => 'datetime'];
    }
}

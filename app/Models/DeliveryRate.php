<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class DeliveryRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'pay_on_delivery' => 'boolean', 'fee' => 'integer'];
    }
}

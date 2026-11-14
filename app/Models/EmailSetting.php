<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class EmailSetting extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['content'];

    protected function casts(): array
    {
        return ['content' => 'encrypted:array'];
    }
}

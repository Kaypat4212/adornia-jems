<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class HomepageSection extends Model
{
    protected $guarded = ['id'];
    protected function casts(): array
    {
        return ['content' => 'array', 'active' => 'boolean'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'type', 'detail', 'description', 'price', 'stock', 'art', 'tag', 'image', 'active'];

    protected function casts(): array
    {
        return ['price' => 'integer', 'stock' => 'integer', 'active' => 'boolean'];
    }
}

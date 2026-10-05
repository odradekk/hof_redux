<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BoardMessage extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['author_color' => ''];

    protected function casts(): array
    {
        return ['report' => 'array', 'details' => 'array', 'public' => 'boolean', 'published' => 'boolean'];
    }
}

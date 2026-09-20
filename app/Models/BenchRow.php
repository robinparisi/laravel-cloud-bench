<?php

namespace App\Models;

use Database\Factories\BenchRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @use HasFactory<BenchRowFactory>
 */
class BenchRow extends Model
{
    /** @use HasFactory<BenchRowFactory> */
    use HasFactory;

    protected $fillable = [
        'token',
        'value',
        'payload',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleCapacity extends Model
{
    public const VALUES = [7, 10, 12, 13, 15, 17, 18, 21, 23, 32];

    protected $fillable = [
        'seats',
    ];
}

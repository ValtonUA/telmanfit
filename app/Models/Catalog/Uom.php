<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

class Uom extends Model
{
    public $timestamps = false;

    protected $table = 'uoms';

    protected $fillable = ['code', 'label', 'kind'];
}

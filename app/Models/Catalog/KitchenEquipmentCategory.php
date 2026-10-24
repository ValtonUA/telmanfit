<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

class KitchenEquipmentCategory extends Model
{
    public $timestamps = false;

    protected $table = 'kitchen_equipment_categories';

    protected $fillable = ['name'];
}

<?php

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Model;

class KitchenEquipment extends Model
{
    public $timestamps = false;

    protected $table = 'kitchen_equipment';

    protected $fillable = ['name', 'kitchen_equipment_category_id', 'image_url'];
}

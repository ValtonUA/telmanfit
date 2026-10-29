<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/auth2-bootstrap.php';

DB::table('uoms')->insertOrIgnore(['code' => 'g', 'kind' => 'mass', 'label' => 'gram']);

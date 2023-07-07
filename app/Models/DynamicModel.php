<?php

namespace App\Models;

use App\Traits\BindsDynamically;
use Illuminate\Database\Eloquent\Model;

class DynamicModel extends Model
{
    use BindsDynamically;
    public $timestamps = false;
}

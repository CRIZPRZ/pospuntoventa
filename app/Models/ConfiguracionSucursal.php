<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionSucursal extends Model
{
    protected $table = 'configuraciones_sucursal';

    protected $fillable = ['empresa_id', 'sucursal_id', 'config'];

    protected $casts = ['config' => 'array'];
}

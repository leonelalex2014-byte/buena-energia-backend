<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Administrador extends Model
{
    protected $table = 'administrador';
    protected $primaryKey = 'id_administrador';

    protected $fillable = [
        'nombre',
        'email',
        'contrasena',
    ];

    // Ocultar la contraseña por seguridad cuando se devuelva en un JSON
    protected $hidden = [
        'contrasena',
    ];
}

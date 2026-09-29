<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Administrador extends Model
{
    use HasApiTokens;

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

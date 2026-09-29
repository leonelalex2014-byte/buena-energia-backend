<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    protected $table = 'clientes';
    protected $primaryKey = 'id_cliente';

    protected $fillable = [
        'nombre',
        'telefono',
        'email',
        'contrasena',
    ];

    // Ocultar la contraseña por seguridad en respuestas JSON
    protected $hidden = [
        'contrasena',
    ];

    // Relación: Un cliente puede tener muchos pedidos
    public function pedidos()
    {
        return $this->hasMany(Pedido::class, 'id_cliente', 'id_cliente');
    }
}

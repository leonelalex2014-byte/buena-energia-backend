<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    protected $table = 'pedidos';
    protected $primaryKey = 'id_pedido';
    public $timestamps = false; // Desactívalo si no usascreated_at/updated_at

    protected $fillable = [
        'id_cliente',
        'fecha',
        'tipo_entrega',
        'estado',
        'total'
    ];

    public function cliente() {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }

    public function detalles() {
        return $this->hasMany(PedidoDetalle::class, 'id_pedido');
    }
}

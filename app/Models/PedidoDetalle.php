<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PedidoDetalle extends Model
{
    protected $table = 'pedido_detalles';
    protected $primaryKey = 'id_detalle';
    public $timestamps = false;

    protected $fillable = [
        'id_pedido',
        'id_variante',
        'cantidad',
        'precio_unitario'
    ];

    public function variante() {
        return $this->belongsTo(ProductoVariante::class, 'id_variante');
    }
}

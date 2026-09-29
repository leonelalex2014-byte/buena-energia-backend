<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'productos';
    protected $primaryKey = 'id_producto';

    protected $fillable = ['nombre', 'descripcion', 'precio', 'categoria', 'imagen'];

    // Relación de 1 a N con ProductoVariante
    public function variantes()
    {
        return $this->hasMany(ProductoVariante::class, 'id_producto', 'id_producto');
    }
}

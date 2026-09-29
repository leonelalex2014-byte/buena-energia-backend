<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Pedido;
use App\Models\ProductoVariante;

class PedidoController extends Controller
{
    public function store(Request $request)
    {
        return DB::transaction(function () use ($request) {
            // 1. Crear el Pedido
            $pedido = Pedido::create([
                'id_cliente'   => $request->id_cliente,
                'fecha'        => now(),
                'tipo_entrega' => $request->tipo_entrega,
                'estado'       => 'Pendiente',
                'total'        => $request->total
            ]);

            // 2. Guardar Detalles y Descontar Stock
            foreach ($request->items as $item) {
                $pedido->detalles()->create([
                    'id_variante'     => $item['id_variante'],
                    'cantidad'        => $item['cantidad'],
                    'precio_unitario' => $item['precio_unitario']
                ]);

                // Restar stock en tiempo real
                ProductoVariante::where('id_variante', $item['id_variante'])
                    ->decrement('stock', $item['cantidad']);
            }

            return response()->json([
                'message' => 'Pedido registrado con éxito',
                'pedido'  => $pedido
            ], 201);
        });
    }
}

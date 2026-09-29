<?php

use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\ProductoController;


Route::post('/productos/generar-imagen', [ProductoController::class, 'generarImagen']);
Route::get('/productos', [ProductoController::class, 'index']);
Route::get('/test-pedido', function () {
    $request = new \Illuminate\Http\Request([
        'id_cliente'   => 1,
        'tipo_entrega' => 'Llevado_Domicilio',
        'total'        => 700,
        'items'        => [
            [
                'id_variante'     => 1,
                'cantidad'        => 2,
                'precio_unitario' => 350
            ]
        ]
    ]);

    return app(PedidoController::class)->store($request);
});

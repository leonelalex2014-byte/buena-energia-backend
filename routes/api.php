<?php

use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminProductoController;
use App\Http\Controllers\ProductoController;
use Illuminate\Support\Facades\Route;


Route::post('/productos/generar-imagen', [ProductoController::class, 'generarImagen']);
Route::get('/productos', [ProductoController::class, 'index']);
Route::prefix('admin')->group(function () {
    Route::post('/register', [AdminAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::post('/productos', [AdminProductoController::class, 'store']);
    });
});

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

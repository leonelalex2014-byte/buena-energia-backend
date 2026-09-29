<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductoSeeder extends Seeder
{
    public function run(): void
    {
        $productos = [
            [
                'nombre' => 'Bóxer de Algodón Clásico',
                'descripcion' => 'Bóxer de algodón suave con elástico personalizado.',
                'precio' => 180.00,
                'categoria' => 'Hombre',
                'imagen' => '/images/boxer-gris.png',
                'variantes' => [
                    ['talle' => 'M', 'color' => 'Gris', 'stock' => 15],
                    ['talle' => 'L', 'color' => 'Negro', 'stock' => 20],
                    ['talle' => 'M', 'color' => 'Azul', 'stock' => 12],
                    ['talle' => 'M', 'color' => 'Rojo', 'stock' => 10],
                ],
            ],
            [
                'nombre' => 'Sutién Clásico',
                'descripcion' => 'Sutién con soporte ligero y diseño clásico para uso diario.',
                'precio' => 650.00,
                'categoria' => 'Mujer',
                'imagen' => '/images/bra-classic.png',
                'variantes' => [
                    ['talle' => 'M', 'color' => 'Rojo', 'stock' => 8],
                    ['talle' => 'L', 'color' => 'Negro', 'stock' => 10],
                    ['talle' => 'M', 'color' => 'Azul', 'stock' => 12],
                ],
            ],
        ];

        foreach ($productos as $producto) {
            $productoId = DB::table('productos')->insertGetId([
                'nombre' => $producto['nombre'],
                'descripcion' => $producto['descripcion'],
                'precio' => $producto['precio'],
                'categoria' => $producto['categoria'],
                'imagen' => $producto['imagen'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $variantes = array_map(function ($variante) use ($productoId) {
                return [
                    'id_producto' => $productoId,
                    'talle' => $variante['talle'],
                    'color' => $variante['color'],
                    'stock' => $variante['stock'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, $producto['variantes']);

            DB::table('producto_variantes')->insert($variantes);
        }

        $adminEmail = config('admin.bootstrap_email');
        $adminPassword = config('admin.bootstrap_password');

        if (is_string($adminEmail) && $adminEmail !== '' && is_string($adminPassword) && $adminPassword !== '') {
            DB::table('administrador')->updateOrInsert(
                ['email' => $adminEmail],
                [
                    'nombre' => config('admin.bootstrap_name', 'Administrador'),
                    'contrasena' => bcrypt($adminPassword),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}

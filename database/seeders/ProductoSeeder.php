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
                'imagen' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1200&q=80',
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
                'imagen' => 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&fit=crop&w=1200&q=80',
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

        DB::table('administrador')->updateOrInsert(
            ['email' => 'leonelalex2014@gmail.com'],
            [
                'nombre' => 'Leonel',
                'contrasena' => bcrypt('alexander500'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}

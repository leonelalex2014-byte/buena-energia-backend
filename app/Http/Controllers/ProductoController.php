<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductoController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = DB::table('productos');

            if ($request->filled('categoria')) {
                $query->whereRaw('LOWER(categoria) = ?', [mb_strtolower($request->input('categoria'))]);
            }

            $productos = $query->get();

            $productosTransformados = $productos->map(function ($producto) {

                // Consultamos las variantes reales de la tabla creada en la migración
                $variantes = DB::table('producto_variantes')
                    ->where('id_producto', $producto->id_producto)
                    ->get()
                    ->map(fn ($variante) => [
                        'id_variante' => $variante->id_variante,
                        'talle' => $variante->talle,
                        'color' => $variante->color,
                        'stock' => $variante->stock,
                        'imagen_url' => $this->imageUrl($variante->imagen),
                    ]);

                $colors = $variantes->unique('color')->map(function ($variante) use ($producto) {
                    $colorName = $variante['color'];
                    $hexMap = [
                        'Gris' => '#6b7280',
                        'Azul' => '#3b82f6',
                        'Azul marino' => '#1e3a8a',
                        'Celeste' => '#7dd3fc',
                        'Rojo' => '#ef4444',
                        'Negro' => '#111827',
                        'Blanco' => '#ffffff',
                        'Nude' => '#d6b89c',
                        'Rosa' => '#e879a9',
                        'Lila' => '#c4a1e8',
                        'Vino' => '#722f37',
                    ];

                    $productName = strtolower($producto->nombre);
                    $isLegacyBra = str_contains($productName, 'sutién') || str_contains($productName, 'sutien');
                    $isLegacyBoxer = str_contains($productName, 'bóxer de algodón clásico');

                    $imgMap = $isLegacyBra
                        ? [
                            'Rojo' => '/images/bra-red.png',
                            'Negro' => '/images/bra-classic.png',
                            'Azul' => '/images/bra-blue.png',
                        ]
                        : ($isLegacyBoxer
                            ? [
                                'Gris' => '/images/boxer-gris.png',
                                'Azul' => '/images/boxer-azul.png',
                                'Rojo' => '/images/boxer-rojo.png',
                                'Negro' => '/images/boxer-negro.png',
                            ]
                            : []);

                    return [
                        'name' => $colorName,
                        'hex' => $hexMap[$colorName] ?? '#000000',
                        'image' => $variante['imagen_url'] ?? $this->imageUrl($imgMap[$colorName] ?? $producto->imagen),
                    ];
                })->values();

                return [
                    'id_producto' => $producto->id_producto,
                    'nombre' => $producto->nombre,
                    'descripcion' => $producto->descripcion,
                    'precio' => $producto->precio,
                    'categoria' => $producto->categoria,
                    'imagen_url' => $this->imageUrl($producto->imagen),
                    'es_nuevo' => true,
                    'oferta' => false,
                    'colors' => $colors,
                    'variantes' => $variantes->values(),
                ];
            });

            return response()->json($productosTransformados);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function imageUrl(?string $image): ?string
    {
        if (! $image) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $image)) {
            return $image;
        }

        return url('/'.ltrim($image, '/'));
    }

}

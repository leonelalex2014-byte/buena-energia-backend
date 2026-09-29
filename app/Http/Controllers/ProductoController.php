<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
                    ]);

                $colors = $variantes->unique('color')->map(function ($variante) use ($producto) {
                    $colorName = $variante['color'];
                    $hexMap = [
                        'Gris' => '#6b7280',
                        'Azul' => '#3b82f6',
                        'Rojo' => '#ef4444',
                        'Negro' => '#111827'
                    ];

                    $isBra = str_contains(strtolower($producto->nombre), 'sutién') || str_contains(strtolower($producto->nombre), 'sutien');

                    $imgMap = $isBra
                        ? [
                            'Rojo' => '/images/bra-red.png',
                            'Negro' => '/images/bra-classic.png',
                            'Azul' => '/images/bra-blue.png',
                        ]
                        : [
                            'Gris' => '/images/boxer-gris.png',
                            'Azul' => '/images/boxer-azul.png',
                            'Rojo' => '/images/boxer-rojo.png',
                            'Negro' => '/images/boxer-negro.png',
                        ];

                    return [
                        'name' => $colorName,
                        'hex' => $hexMap[$colorName] ?? '#000000',
                        'image' => $this->imageUrl($imgMap[$colorName] ?? $producto->imagen),
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

    /**
     * Envía la descripción de la prenda a ComfyUI para generar la imagen.
     */
    public function generarImagen(Request $request)
    {
        // 1. Validar la entrada recibida desde el frontend (Vue / Postman)
        $request->validate([
            'prompt' => 'required|string',
        ]);

        // 2. Ruta al JSON que exportamos de ComfyUI
        $jsonPath = storage_path('app/ropa.json');

        if (!file_exists($jsonPath)) {
            return response()->json([
                'error' => 'El archivo ropa.json no se encuentra en storage/app/'
            ], 404);
        }

        // 3. Cargar y parsear el workflow en un array PHP
        $workflow = json_decode(file_get_contents($jsonPath), true);

        // 4. Inyectar datos dinámicos utilizando los IDs exactos de tu ropa.json
        // Nodo "6": CLIPTextEncode (Prompt Positivo)
        $workflow['6']['inputs']['text'] = $request->input('prompt');

        // Nodo "3": KSampler (Cambiamos la semilla para variar la imagen)
        $workflow['3']['inputs']['seed'] = random_int(1, 999999999999999);

        // 5. Enviar la petición HTTP POST a ComfyUI
        try {
            $response = Http::timeout(10)->post('http://127.0.0.1:8188/prompt', [
                'prompt' => $workflow
            ]);

            if ($response->successful()) {
                $promptId = $response->json()['prompt_id'];

                return response()->json([
                    'status' => 'success',
                    'message' => 'Generación de imagen iniciada con éxito',
                    'prompt_id' => $promptId
                ], 200);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'No se pudo conectar con el servidor de ComfyUI (127.0.0.1:8188)',
                'detalles' => $e->getMessage()
            ], 500);
        }

        return response()->json(['error' => 'ComfyUI devolvió una respuesta con error'], 500);
    }
}

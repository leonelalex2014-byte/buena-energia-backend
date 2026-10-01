<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminProductoController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'precio' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'categoria' => ['required', 'string', 'max:255'],
            'imagen' => ['nullable', 'string', 'max:255'],
            'imagen_archivo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'variantes' => ['required', 'array', 'min:1', 'max:40'],
            'variantes.*.talle' => ['required', 'string', 'max:255'],
            'variantes.*.color' => ['required', 'string', 'max:255'],
            'variantes.*.stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'variantes.*.imagen' => ['nullable', 'string', 'max:255'],
        ]);

        $combinations = array_map(
            fn (array $variant) => mb_strtolower(trim($variant['talle']).'|'.trim($variant['color'])),
            $validated['variantes'],
        );

        if (count($combinations) !== count(array_unique($combinations))) {
            throw ValidationException::withMessages([
                'variantes' => 'No repitas la combinación de talle y color.',
            ]);
        }

        $storedImagePath = null;

        if ($request->hasFile('imagen_archivo')) {
            $storedImagePath = $request->file('imagen_archivo')->storePublicly('productos', 'public');

            if (! is_string($storedImagePath)) {
                return response()->json([
                    'message' => 'No se pudo guardar la imagen del producto.',
                ], 500);
            }
        }

        $imagePath = $storedImagePath
            ? '/storage/'.$storedImagePath
            : ($validated['imagen'] ?? null);

        try {
            $product = DB::transaction(function () use ($validated, $imagePath) {
                $product = Producto::create([
                    'nombre' => $validated['nombre'],
                    'descripcion' => $validated['descripcion'] ?? null,
                    'precio' => $validated['precio'],
                    'categoria' => $validated['categoria'],
                    'imagen' => $imagePath,
                ]);

                $product->variantes()->createMany($validated['variantes']);

                return $product->load('variantes');
            });
        } catch (Throwable $error) {
            if ($storedImagePath) {
                Storage::disk('public')->delete($storedImagePath);
            }

            throw $error;
        }

        return response()->json([
            'message' => 'Producto creado correctamente.',
            'product' => [
                'id_producto' => $product->id_producto,
                'nombre' => $product->nombre,
                'descripcion' => $product->descripcion,
                'precio' => $product->precio,
                'categoria' => $product->categoria,
                'imagen_url' => $product->imagen
                    ? (preg_match('/^https?:\/\//i', $product->imagen) ? $product->imagen : url('/'.ltrim($product->imagen, '/')))
                    : null,
                'variantes' => $product->variantes,
            ],
        ], 201);
    }
}

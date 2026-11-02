<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PedidoDetalle;
use App\Models\Producto;
use App\Models\ProductoVariante;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminProductoController extends Controller
{
    public function updateStock(Request $request, int $variantId): JsonResponse
    {
        $validated = $request->validate([
            'stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ]);

        $variant = ProductoVariante::query()->findOrFail($variantId);
        $variant->update(['stock' => $validated['stock']]);

        return response()->json([
            'message' => 'Stock actualizado correctamente.',
            'variant' => [
                'id_variante' => $variant->id_variante,
                'stock' => $variant->stock,
            ],
        ]);
    }

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

    public function update(Request $request, Producto $producto): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'precio' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'categoria' => ['required', 'string', 'max:255'],
            'imagen' => ['nullable', 'string', 'max:255'],
            'imagen_archivo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'variantes' => ['required', 'array', 'min:1', 'max:40'],
            'variantes.*.id_variante' => [
                'sometimes',
                'nullable',
                'integer',
                'distinct',
                Rule::exists('producto_variantes', 'id_variante')
                    ->where('id_producto', $producto->id_producto),
            ],
            'variantes.*.talle' => ['required', 'string', 'max:255'],
            'variantes.*.color' => ['required', 'string', 'max:255'],
            'variantes.*.stock' => ['required', 'integer', 'min:0', 'max:2147483647'],
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
        $previousImagePath = $this->storedProductImagePath($producto->imagen);

        try {
            $updatedProduct = DB::transaction(function () use ($validated, $imagePath, $producto): Producto {
                $product = Producto::query()
                    ->whereKey($producto->id_producto)
                    ->lockForUpdate()
                    ->firstOrFail();
                $existingVariants = $product->variantes()
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id_variante');
                $submittedVariantIds = collect($validated['variantes'])
                    ->pluck('id_variante')
                    ->filter()
                    ->map(fn ($id): int => (int) $id);
                $removedVariants = $existingVariants->reject(
                    fn (ProductoVariante $variant): bool => $submittedVariantIds->contains($variant->id_variante),
                );

                if ($removedVariants->isNotEmpty()) {
                    $hasOrderHistory = PedidoDetalle::query()
                        ->whereIn('id_variante', $removedVariants->modelKeys())
                        ->exists();

                    if ($hasOrderHistory) {
                        throw ValidationException::withMessages([
                            'variantes' => 'No se pueden quitar variantes que forman parte de pedidos. Conserva esas variantes y deja su stock en 0.',
                        ]);
                    }
                }

                foreach ($validated['variantes'] as $variantData) {
                    if (empty($variantData['id_variante'])) {
                        continue;
                    }

                    $existingVariant = $existingVariants->get((int) $variantData['id_variante']);

                    if (! $existingVariant) {
                        throw ValidationException::withMessages([
                            'variantes' => 'Una de las variantes cambió mientras se editaba el producto. Actualiza el catálogo e inténtalo de nuevo.',
                        ]);
                    }

                    if (
                        $existingVariant->talle !== $variantData['talle']
                        || $existingVariant->color !== $variantData['color']
                    ) {
                        $hasOrderHistory = PedidoDetalle::query()
                            ->where('id_variante', $existingVariant->id_variante)
                            ->exists();

                        if ($hasOrderHistory) {
                            throw ValidationException::withMessages([
                                'variantes' => 'No se puede cambiar el talle o color de una variante que forma parte de pedidos. Agrega una nueva variante y deja la anterior con stock 0.',
                            ]);
                        }
                    }
                }

                $product->update([
                    'nombre' => $validated['nombre'],
                    'descripcion' => $validated['descripcion'] ?? null,
                    'precio' => $validated['precio'],
                    'categoria' => $validated['categoria'],
                    'imagen' => $imagePath,
                ]);

                foreach ($removedVariants as $removedVariant) {
                    $removedVariant->delete();
                }

                foreach ($validated['variantes'] as $variantData) {
                    $variantAttributes = [
                        'talle' => $variantData['talle'],
                        'color' => $variantData['color'],
                        'stock' => $variantData['stock'],
                    ];

                    if (! empty($variantData['id_variante'])) {
                        $existingVariants->get((int) $variantData['id_variante'])->update($variantAttributes);
                    } else {
                        $product->variantes()->create($variantAttributes);
                    }
                }

                return $product->load('variantes');
            });
        } catch (Throwable $error) {
            if ($storedImagePath) {
                Storage::disk('public')->delete($storedImagePath);
            }

            throw $error;
        }

        $newStoredImagePath = $this->storedProductImagePath($updatedProduct->imagen);

        if ($previousImagePath && $previousImagePath !== $newStoredImagePath) {
            Storage::disk('public')->delete($previousImagePath);
        }

        return response()->json([
            'message' => 'Producto actualizado correctamente.',
            'product' => [
                'id_producto' => $updatedProduct->id_producto,
                'nombre' => $updatedProduct->nombre,
                'descripcion' => $updatedProduct->descripcion,
                'precio' => $updatedProduct->precio,
                'categoria' => $updatedProduct->categoria,
                'imagen_url' => $updatedProduct->imagen
                    ? (preg_match('/^https?:\/\//i', $updatedProduct->imagen) ? $updatedProduct->imagen : url('/'.ltrim($updatedProduct->imagen, '/')))
                    : null,
                'variantes' => $updatedProduct->variantes,
            ],
        ]);
    }

    private function storedProductImagePath(?string $image): ?string
    {
        $path = parse_url($image ?? '', PHP_URL_PATH);

        if (
            ! is_string($path)
            || str_contains($path, '\\')
            || preg_match('#^/storage/productos/[^/]+$#', $path) !== 1
        ) {
            return null;
        }

        $filename = substr($path, strlen('/storage/productos/'));

        if ($filename === '.' || $filename === '..') {
            return null;
        }

        return 'productos/'.$filename;
    }
}

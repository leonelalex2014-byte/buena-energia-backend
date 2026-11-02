<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\ProductoVariante;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PedidoController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cliente.nombre' => ['required', 'string', 'max:255'],
            'cliente.telefono' => ['required', 'string', 'max:30'],
            'entrega' => ['required', 'in:retiro,domicilio'],
            'direccion' => ['nullable', 'required_if:entrega,domicilio', 'string', 'max:500'],
            'notas' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.id_variante' => ['required', 'integer', 'distinct', 'exists:producto_variantes,id_variante'],
            'items.*.cantidad' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'captcha_challenge_id' => ['required', 'uuid'],
            'captcha_answer' => ['required', 'integer', 'between:2,18'],
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['website'] ?? '') !== '') {
            return response()->json([
                'message' => 'No pudimos verificar la solicitud. Solicita un nuevo desafío.',
            ], 422);
        }

        $expectedAnswer = Cache::pull('checkout-captcha:'.$validated['captcha_challenge_id']);

        if (! is_int($expectedAnswer) || $expectedAnswer !== (int) $validated['captcha_answer']) {
            return response()->json([
                'message' => 'No pudimos verificar la solicitud. Solicita un nuevo desafío.',
            ], 422);
        }

        $order = DB::transaction(function () use ($validated): Pedido {
            $variantIds = collect($validated['items'])
                ->pluck('id_variante')
                ->sort()
                ->values();
            $variants = ProductoVariante::query()
                ->with('producto')
                ->whereIn('id_variante', $variantIds)
                ->orderBy('id_variante')
                ->lockForUpdate()
                ->get()
                ->keyBy('id_variante');
            $totalInCents = 0;
            $maximumTotalInCents = 9999999999;

            foreach ($validated['items'] as $item) {
                $variant = $variants->get($item['id_variante']);

                if (! $variant || $variant->stock < $item['cantidad']) {
                    $available = $variant?->stock ?? 0;

                    throw ValidationException::withMessages([
                        'items' => "No hay suficiente stock para {$variant?->producto?->nombre} ({$variant?->talle}, {$variant?->color}). Disponibles: {$available}.",
                    ]);
                }

                $unitPriceInCents = (int) round((float) $variant->producto->precio * 100);

                if ($unitPriceInCents > intdiv($maximumTotalInCents - $totalInCents, $item['cantidad'])) {
                    throw ValidationException::withMessages([
                        'items' => 'El total del pedido supera el máximo permitido.',
                    ]);
                }

                $totalInCents += $unitPriceInCents * $item['cantidad'];
            }

            $customer = Cliente::create([
                'nombre' => $validated['cliente']['nombre'],
                'telefono' => $validated['cliente']['telefono'],
                'direccion' => $validated['entrega'] === 'domicilio'
                    ? $validated['direccion']
                    : 'Retiro en el local',
            ]);

            $order = Pedido::create([
                'id_cliente' => $customer->id_cliente,
                'fecha' => now(),
                'tipo_entrega' => $validated['entrega'],
                'estado' => 'Pendiente',
                'total' => number_format($totalInCents / 100, 2, '.', ''),
                'notas' => $validated['notas'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $variant = $variants->get($item['id_variante']);
                $order->detalles()->create([
                    'id_variante' => $variant->id_variante,
                    'cantidad' => $item['cantidad'],
                    'precio_unitario' => $variant->producto->precio,
                ]);
            }

            return $order->load(['cliente', 'detalles.variante.producto']);
        });

        return response()->json([
            'message' => 'Pedido registrado. La tienda confirmará la disponibilidad.',
            'order' => $this->orderData($order),
        ], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vista' => ['sometimes', 'in:activos,historial'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $view = $validated['vista'] ?? 'activos';
        $query = Pedido::query()
            ->with(['cliente', 'detalles.variante.producto']);

        if ($view === 'historial') {
            $query->whereIn('estado', ['Cancelado', 'Entregado']);
        } else {
            $query->whereIn('estado', ['Pendiente', 'Confirmado']);
        }

        $perPage = (int) ($validated['per_page'] ?? 10);
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min((int) ($validated['page'] ?? 1), $lastPage);
        $orders = $query
            ->orderByDesc('fecha')
            ->orderByDesc('id_pedido')
            ->paginate($perPage, ['*'], 'page', $page);
        $pendingCount = Pedido::query()
            ->where('estado', 'Pendiente')
            ->count();

        return response()->json([
            'orders' => $orders->getCollection()
                ->map(fn (Pedido $order): array => $this->orderData($order))
                ->values(),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
            'pending_count' => $pendingCount,
        ]);
    }

    public function updateStatus(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'estado' => ['required', 'in:Confirmado,Cancelado,Entregado'],
        ]);

        $order = DB::transaction(function () use ($orderId, $validated): Pedido {
            $order = Pedido::query()
                ->with('detalles')
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();
            $nextStatus = $validated['estado'];

            if ($order->estado === $nextStatus) {
                return $order->load(['cliente', 'detalles.variante.producto']);
            }

            if ($order->estado === 'Pendiente' && $nextStatus === 'Confirmado') {
                $variants = $this->lockOrderVariants($order);

                foreach ($order->detalles as $detail) {
                    $variant = $variants->get($detail->id_variante);

                    if (! $variant || $variant->stock < $detail->cantidad) {
                        $name = $variant?->producto?->nombre ?? 'una variante';
                        $size = $variant?->talle ?? '';
                        $color = $variant?->color ?? '';

                        throw ValidationException::withMessages([
                            'estado' => "No se puede confirmar el pedido: stock insuficiente para {$name} ({$size}, {$color}).",
                        ]);
                    }
                }

                foreach ($order->detalles as $detail) {
                    $updated = ProductoVariante::query()
                        ->whereKey($detail->id_variante)
                        ->where('stock', '>=', $detail->cantidad)
                        ->decrement('stock', $detail->cantidad);

                    if ($updated !== 1) {
                        throw ValidationException::withMessages([
                            'estado' => 'El stock cambió mientras se confirmaba el pedido. Actualiza el inventario e inténtalo de nuevo.',
                        ]);
                    }
                }
            } elseif ($order->estado === 'Confirmado' && $nextStatus === 'Cancelado') {
                $variants = $this->lockOrderVariants($order);

                foreach ($order->detalles as $detail) {
                    $variant = $variants->get($detail->id_variante);

                    if (! $variant || $variant->stock > 2147483647 - $detail->cantidad) {
                        throw ValidationException::withMessages([
                            'estado' => 'No se puede cancelar el pedido porque no es posible devolver todo el stock.',
                        ]);
                    }
                }

                foreach ($order->detalles as $detail) {
                    ProductoVariante::query()
                        ->whereKey($detail->id_variante)
                        ->increment('stock', $detail->cantidad);
                }
            } elseif (! ($order->estado === 'Pendiente' && $nextStatus === 'Cancelado')
                && ! ($order->estado === 'Confirmado' && $nextStatus === 'Entregado')) {
                throw ValidationException::withMessages([
                    'estado' => 'No se puede cambiar el pedido desde su estado actual.',
                ]);
            }

            $order->update(['estado' => $nextStatus]);

            return $order->load(['cliente', 'detalles.variante.producto']);
        });

        return response()->json([
            'message' => 'Estado del pedido actualizado correctamente.',
            'order' => $this->orderData($order),
        ]);
    }

    /**
     * @return Collection<int, ProductoVariante>
     */
    private function lockOrderVariants(Pedido $order): Collection
    {
        $variantIds = $order->detalles->pluck('id_variante')->sort()->values();

        return ProductoVariante::query()
            ->with('producto')
            ->whereIn('id_variante', $variantIds)
            ->orderBy('id_variante')
            ->lockForUpdate()
            ->get()
            ->keyBy('id_variante');
    }

    private function orderData(Pedido $order): array
    {
        return [
            'id_pedido' => $order->id_pedido,
            'fecha' => $order->fecha,
            'estado' => $order->estado,
            'tipo_entrega' => $order->tipo_entrega,
            'total' => number_format((float) $order->total, 2, '.', ''),
            'notas' => $order->notas,
            'cliente' => [
                'nombre' => $order->cliente->nombre,
                'telefono' => $order->cliente->telefono,
                'direccion' => $order->cliente->direccion,
            ],
            'detalles' => $order->detalles->map(fn ($detail): array => [
                'cantidad' => $detail->cantidad,
                'precio_unitario' => number_format((float) $detail->precio_unitario, 2, '.', ''),
                'nombre' => $detail->variante->producto->nombre,
                'categoria' => $detail->variante->producto->categoria,
                'talle' => $detail->variante->talle,
                'color' => $detail->variante->color,
            ])->values()->all(),
        ];
    }
}

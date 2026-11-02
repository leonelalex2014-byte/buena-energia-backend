<?php

namespace Tests\Feature;

use App\Models\Administrador;
use App\Models\Producto;
use App\Models\ProductoVariante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_a_pending_order_using_server_prices(): void
    {
        $variant = $this->createVariant(stock: 5, price: '125.50');
        $payload = $this->orderPayload([[
            'id_variante' => $variant->id_variante,
            'cantidad' => 2,
        ]]);
        $payload['total'] = 1;
        $payload['items'][0]['precio_unitario'] = 0.01;

        $response = $this->postJson('/api/pedidos', $payload);

        $response->assertCreated()
            ->assertJsonPath('order.estado', 'Pendiente')
            ->assertJsonPath('order.total', '251.00')
            ->assertJsonPath('order.detalles.0.precio_unitario', '125.50')
            ->assertJsonPath('order.notas', 'Llamar antes de entregar.');

        $this->assertDatabaseCount('pedidos', 1);
        $this->assertDatabaseCount('pedido_detalles', 1);
        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variant->id_variante,
            'stock' => 5,
        ]);
    }

    public function test_order_creation_rejects_insufficient_stock_and_reused_captcha(): void
    {
        $variant = $this->createVariant(stock: 1);
        $payload = $this->orderPayload([[
            'id_variante' => $variant->id_variante,
            'cantidad' => 2,
        ]]);

        $this->postJson('/api/pedidos', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $payload['items'][0]['cantidad'] = 1;
        $this->postJson('/api/pedidos', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No pudimos verificar la solicitud. Solicita un nuevo desafío.');

        $this->assertDatabaseCount('pedidos', 0);
        $this->assertDatabaseCount('clientes', 0);
    }

    public function test_admin_confirmation_deducts_stock_and_cancellation_restores_it_once(): void
    {
        $variant = $this->createVariant(stock: 5);
        $order = $this->createOrder($variant, quantity: 3);
        $token = $this->adminToken();

        $this->withToken($token)
            ->getJson('/api/admin/pedidos')
            ->assertOk()
            ->assertJsonPath('0.id_pedido', $order)
            ->assertJsonPath('0.cliente.nombre', 'Cliente de prueba');

        $this->withToken($token)
            ->patchJson("/api/admin/pedidos/{$order}", ['estado' => 'Confirmado'])
            ->assertOk()
            ->assertJsonPath('order.estado', 'Confirmado');

        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variant->id_variante,
            'stock' => 2,
        ]);

        $this->withToken($token)
            ->patchJson("/api/admin/pedidos/{$order}", ['estado' => 'Confirmado'])
            ->assertOk();
        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variant->id_variante,
            'stock' => 2,
        ]);

        $this->withToken($token)
            ->patchJson("/api/admin/pedidos/{$order}", ['estado' => 'Cancelado'])
            ->assertOk()
            ->assertJsonPath('order.estado', 'Cancelado');
        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variant->id_variante,
            'stock' => 5,
        ]);

        $this->withToken($token)
            ->patchJson("/api/admin/pedidos/{$order}", ['estado' => 'Entregado'])
            ->assertUnprocessable();
    }

    public function test_admin_cannot_confirm_a_pending_order_after_stock_runs_out(): void
    {
        $variant = $this->createVariant(stock: 5);
        $firstOrder = $this->createOrder($variant, quantity: 4);
        $secondOrder = $this->createOrder($variant, quantity: 4);
        $token = $this->adminToken();

        $this->withToken($token)
            ->patchJson("/api/admin/pedidos/{$firstOrder}", ['estado' => 'Confirmado'])
            ->assertOk();

        $this->withToken($token)
            ->patchJson("/api/admin/pedidos/{$secondOrder}", ['estado' => 'Confirmado'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variant->id_variante,
            'stock' => 1,
        ]);
        $this->assertDatabaseHas('pedidos', [
            'id_pedido' => $secondOrder,
            'estado' => 'Pendiente',
        ]);
    }

    public function test_order_management_requires_an_administrator_token(): void
    {
        $this->getJson('/api/admin/pedidos')->assertUnauthorized();
        $this->patchJson('/api/admin/pedidos/1', ['estado' => 'Confirmado'])
            ->assertUnauthorized();
    }

    private function createVariant(int $stock, string $price = '100.00'): ProductoVariante
    {
        $product = Producto::create([
            'nombre' => 'Producto de prueba',
            'precio' => $price,
            'categoria' => 'Mujer',
        ]);

        return $product->variantes()->create([
            'talle' => 'M',
            'color' => 'Azul',
            'stock' => $stock,
        ]);
    }

    private function createOrder(ProductoVariante $variant, int $quantity): int
    {
        $payload = $this->orderPayload([[
            'id_variante' => $variant->id_variante,
            'cantidad' => $quantity,
        ]]);

        return $this->postJson('/api/pedidos', $payload)->assertCreated()->json('order.id_pedido');
    }

    private function orderPayload(array $items): array
    {
        $challengeId = (string) Str::uuid();
        Cache::put('checkout-captcha:'.$challengeId, 7, now()->addMinutes(5));

        return [
            'cliente' => [
                'nombre' => 'Cliente de prueba',
                'telefono' => '099123456',
            ],
            'entrega' => 'domicilio',
            'direccion' => 'Calle de prueba 123',
            'notas' => 'Llamar antes de entregar.',
            'items' => $items,
            'captcha_challenge_id' => $challengeId,
            'captcha_answer' => 7,
            'website' => '',
        ];
    }

    private function adminToken(): string
    {
        $administrator = Administrador::create([
            'nombre' => 'Admin de prueba',
            'email' => 'admin@example.com',
            'contrasena' => 'hashed-test-password',
        ]);

        return $administrator->createToken('test-admin', ['admin'])->plainTextToken;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Administrador;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoDetalle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_the_configured_admin_key(): void
    {
        config(['admin.registration_key' => 'test-admin-key']);

        $this->postJson('/api/admin/register', $this->registrationData([
            'registration_key' => 'wrong-key',
        ]))->assertForbidden();

        $this->assertDatabaseCount('administrador', 0);
    }

    public function test_admin_can_register_login_and_create_a_product_with_variants(): void
    {
        config(['admin.registration_key' => 'test-admin-key']);

        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $registration->assertCreated()
            ->assertJsonPath('user.tipo', 'administrador');

        $administrator = Administrador::where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('a-long-test-password', $administrator->contrasena));

        $login = $this->postJson('/api/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'a-long-test-password',
        ])->assertOk();

        $token = $login->json('token');
        $productResponse = $this->withToken($token)->postJson('/api/admin/productos', [
            'nombre' => 'Bóxer de prueba',
            'descripcion' => 'Algodón suave',
            'precio' => '249.90',
            'categoria' => 'Hombre',
            'imagen' => '/images/test-product.png',
            'variantes' => [
                ['talle' => 'M', 'color' => 'Negro', 'stock' => 8],
                ['talle' => 'L', 'color' => 'Negro', 'stock' => 0],
            ],
        ]);

        $productResponse->assertCreated()
            ->assertJsonPath('product.nombre', 'Bóxer de prueba')
            ->assertJsonPath('product.imagen_url', url('/images/test-product.png'))
            ->assertJsonPath('product.variantes.0.talle', 'M');

        $productId = $productResponse->json('product.id_producto');
        $this->assertDatabaseHas('productos', [
            'id_producto' => $productId,
            'categoria' => 'Hombre',
            'precio' => '249.90',
        ]);
        $this->assertDatabaseCount('producto_variantes', 2);

        $catalog = $this->getJson('/api/productos?categoria=hombre');
        $this->assertSame(200, $catalog->status(), $catalog->getContent());
        $catalog->assertJsonPath('0.id_producto', $productId)
            ->assertJsonPath('0.imagen_url', url('/images/test-product.png'))
            ->assertJsonPath('0.colors.0.image', url('/images/test-product.png'))
            ->assertJsonPath('0.variantes.0.id_variante', 1);
    }

    public function test_product_creation_requires_an_authenticated_admin(): void
    {
        $this->postJson('/api/admin/productos', [])->assertUnauthorized();
        $this->assertDatabaseCount('productos', 0);
    }

    public function test_admin_can_update_variant_stock_to_zero(): void
    {
        config(['admin.registration_key' => 'test-admin-key']);
        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $token = $registration->json('token');
        $productResponse = $this->withToken($token)->postJson('/api/admin/productos', [
            'nombre' => 'Producto para inventario',
            'precio' => '100.00',
            'categoria' => 'Mujer',
            'variantes' => [
                ['talle' => 'M', 'color' => 'Azul', 'stock' => 3],
            ],
        ])->assertCreated();
        $variantId = $productResponse->json('product.variantes.0.id_variante');

        $this->withToken($token)
            ->patchJson("/api/admin/variantes/{$variantId}/stock", ['stock' => 0])
            ->assertOk()
            ->assertJsonPath('variant.id_variante', $variantId)
            ->assertJsonPath('variant.stock', 0);

        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variantId,
            'stock' => 0,
        ]);
        $this->getJson('/api/productos')
            ->assertOk()
            ->assertJsonPath('0.variantes.0.stock', 0);
    }

    public function test_stock_update_requires_an_admin_and_rejects_negative_values(): void
    {
        $this->patchJson('/api/admin/variantes/1/stock', ['stock' => 0])
            ->assertUnauthorized();

        config(['admin.registration_key' => 'test-admin-key']);
        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $productResponse = $this->withToken($registration->json('token'))
            ->postJson('/api/admin/productos', [
                'nombre' => 'Producto para inventario',
                'precio' => '100.00',
                'categoria' => 'Mujer',
                'variantes' => [
                    ['talle' => 'M', 'color' => 'Azul', 'stock' => 3],
                ],
            ]);
        $variantId = $productResponse->json('product.variantes.0.id_variante');

        $this->withToken($registration->json('token'))
            ->patchJson("/api/admin/variantes/{$variantId}/stock", ['stock' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stock');

        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variantId,
            'stock' => 3,
        ]);
    }

    public function test_admin_can_upload_a_product_image(): void
    {
        Storage::fake('public');
        config(['admin.registration_key' => 'test-admin-key']);

        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $token = $registration->json('token');

        $response = $this->withToken($token)
            ->withHeader('Accept', 'application/json')
            ->post('/api/admin/productos', [
                'nombre' => 'Sostén de prueba',
                'descripcion' => 'Imagen cargada desde archivo',
                'precio' => '320.00',
                'categoria' => 'Mujer',
                'imagen_archivo' => UploadedFile::fake()->create('sosten.png', 100, 'image/png'),
                'variantes' => [
                    ['talle' => 'M', 'color' => 'Azul', 'stock' => 4],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('product.nombre', 'Sostén de prueba');

        $imageUrl = $response->json('product.imagen_url');
        $this->assertStringContainsString('/storage/productos/', $imageUrl);
        $imagePath = substr(parse_url($imageUrl, PHP_URL_PATH), strlen('/storage/'));
        Storage::disk('public')->assertExists($imagePath);
    }

    public function test_product_creation_rejects_missing_database_fields(): void
    {
        config(['admin.registration_key' => 'test-admin-key']);
        $registration = $this->postJson('/api/admin/register', $this->registrationData());

        $this->withToken($registration->json('token'))
            ->postJson('/api/admin/productos', ['nombre' => 'Incompleto'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['precio', 'categoria', 'variantes']);

        $this->assertDatabaseCount('productos', 0);
    }

    public function test_admin_can_update_a_product_and_its_variants(): void
    {
        config(['admin.registration_key' => 'test-admin-key']);
        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $token = $registration->json('token');
        $createdProduct = $this->withToken($token)->postJson('/api/admin/productos', [
            'nombre' => 'Sostén clásico',
            'descripcion' => 'Descripción anterior',
            'precio' => '320.00',
            'categoria' => 'Mujer',
            'imagen' => '/images/sosten-viejo.png',
            'variantes' => [
                ['talle' => '34B', 'color' => 'Negro', 'stock' => 4],
                ['talle' => '36B', 'color' => 'Negro', 'stock' => 2],
            ],
        ])->assertCreated();
        $productId = $createdProduct->json('product.id_producto');
        $retainedVariantId = $createdProduct->json('product.variantes.0.id_variante');
        $removedVariantId = $createdProduct->json('product.variantes.1.id_variante');

        $this->withToken($token)
            ->patchJson("/api/admin/productos/{$productId}", [
                'nombre' => 'Sostén renovado',
                'descripcion' => 'Nueva descripción',
                'precio' => '450.50',
                'categoria' => 'Unisex',
                'imagen' => '/images/sosten-nuevo.png',
                'variantes' => [
                    ['id_variante' => $retainedVariantId, 'talle' => '34B', 'color' => 'Rojo', 'stock' => 0],
                    ['talle' => '38B', 'color' => 'Azul', 'stock' => 7],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Producto actualizado correctamente.')
            ->assertJsonPath('product.nombre', 'Sostén renovado')
            ->assertJsonPath('product.imagen_url', url('/images/sosten-nuevo.png'))
            ->assertJsonPath('product.variantes.0.id_variante', $retainedVariantId)
            ->assertJsonPath('product.variantes.1.talle', '38B');

        $this->assertDatabaseHas('productos', [
            'id_producto' => $productId,
            'nombre' => 'Sostén renovado',
            'descripcion' => 'Nueva descripción',
            'precio' => '450.50',
            'categoria' => 'Unisex',
            'imagen' => '/images/sosten-nuevo.png',
        ]);
        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $retainedVariantId,
            'talle' => '34B',
            'color' => 'Rojo',
            'stock' => 0,
        ]);
        $this->assertDatabaseMissing('producto_variantes', ['id_variante' => $removedVariantId]);
        $this->assertDatabaseCount('producto_variantes', 2);
    }

    public function test_product_update_rejects_variant_ids_from_another_product(): void
    {
        config(['admin.registration_key' => 'test-admin-key']);
        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $token = $registration->json('token');
        $firstProduct = $this->withToken($token)->postJson('/api/admin/productos', [
            'nombre' => 'Primer producto',
            'precio' => '100.00',
            'categoria' => 'Mujer',
            'variantes' => [['talle' => 'M', 'color' => 'Negro', 'stock' => 4]],
        ])->assertCreated()->json('product');
        $secondProduct = $this->withToken($token)->postJson('/api/admin/productos', [
            'nombre' => 'Segundo producto',
            'precio' => '200.00',
            'categoria' => 'Hombre',
            'variantes' => [['talle' => 'L', 'color' => 'Azul', 'stock' => 8]],
        ])->assertCreated()->json('product');
        $foreignVariantId = $secondProduct['variantes'][0]['id_variante'];

        $this->withToken($token)
            ->patchJson("/api/admin/productos/{$firstProduct['id_producto']}", [
                'nombre' => 'Primer producto cambiado',
                'precio' => '100.00',
                'categoria' => 'Mujer',
                'variantes' => [
                    ['id_variante' => $foreignVariantId, 'talle' => 'L', 'color' => 'Azul', 'stock' => 8],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variantes.0.id_variante');

        $this->assertDatabaseHas('productos', [
            'id_producto' => $firstProduct['id_producto'],
            'nombre' => 'Primer producto',
        ]);
        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $foreignVariantId,
            'id_producto' => $secondProduct['id_producto'],
            'stock' => 8,
        ]);
    }

    public function test_product_update_rejects_duplicate_variant_ids(): void
    {
        config(['admin.registration_key' => 'test-admin-key']);
        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $token = $registration->json('token');
        $product = $this->withToken($token)->postJson('/api/admin/productos', [
            'nombre' => 'Producto con variante duplicada',
            'precio' => '100.00',
            'categoria' => 'Mujer',
            'variantes' => [['talle' => 'M', 'color' => 'Negro', 'stock' => 4]],
        ])->assertCreated()->json('product');
        $variantId = $product['variantes'][0]['id_variante'];

        $this->withToken($token)
            ->patchJson("/api/admin/productos/{$product['id_producto']}", [
                'nombre' => 'Producto con variante duplicada',
                'precio' => '100.00',
                'categoria' => 'Mujer',
                'variantes' => [
                    ['id_variante' => $variantId, 'talle' => 'M', 'color' => 'Negro', 'stock' => 4],
                    ['id_variante' => $variantId, 'talle' => 'L', 'color' => 'Rojo', 'stock' => 3],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variantes.1.id_variante');

        $this->assertDatabaseCount('producto_variantes', 1);
        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variantId,
            'talle' => 'M',
            'color' => 'Negro',
            'stock' => 4,
        ]);
    }

    public function test_product_update_preserves_variants_referenced_by_orders(): void
    {
        config(['admin.registration_key' => 'test-admin-key']);
        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $token = $registration->json('token');
        $product = $this->withToken($token)->postJson('/api/admin/productos', [
            'nombre' => 'Producto con pedido',
            'precio' => '100.00',
            'categoria' => 'Mujer',
            'variantes' => [['talle' => 'M', 'color' => 'Negro', 'stock' => 4]],
        ])->assertCreated()->json('product');
        $variantId = $product['variantes'][0]['id_variante'];
        $customer = Cliente::create([
            'nombre' => 'Cliente de prueba',
            'telefono' => '099123456',
            'direccion' => 'Retiro en el local',
        ]);
        $order = Pedido::create([
            'id_cliente' => $customer->id_cliente,
            'fecha' => now(),
            'tipo_entrega' => 'retiro',
            'estado' => 'Pendiente',
            'total' => '100.00',
        ]);
        PedidoDetalle::create([
            'id_pedido' => $order->id_pedido,
            'id_variante' => $variantId,
            'cantidad' => 1,
            'precio_unitario' => '100.00',
        ]);

        $this->withToken($token)
            ->patchJson("/api/admin/productos/{$product['id_producto']}", [
                'nombre' => 'Producto actualizado',
                'precio' => '120.00',
                'categoria' => 'Mujer',
                'variantes' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variantes');

        $this->withToken($token)
            ->patchJson("/api/admin/productos/{$product['id_producto']}", [
                'nombre' => 'Producto actualizado',
                'precio' => '120.00',
                'categoria' => 'Mujer',
                'variantes' => [
                    ['id_variante' => $variantId, 'talle' => 'L', 'color' => 'Rojo', 'stock' => 0],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variantes');

        $this->assertDatabaseHas('pedido_detalles', ['id_variante' => $variantId]);
        $this->assertDatabaseHas('producto_variantes', [
            'id_variante' => $variantId,
            'talle' => 'M',
            'color' => 'Negro',
        ]);
        $this->assertDatabaseHas('productos', [
            'id_producto' => $product['id_producto'],
            'nombre' => 'Producto con pedido',
        ]);
    }

    public function test_admin_can_replace_and_remove_a_product_image(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('productos/original.png', 'original');
        config(['admin.registration_key' => 'test-admin-key']);
        $registration = $this->postJson('/api/admin/register', $this->registrationData());
        $token = $registration->json('token');
        $product = $this->withToken($token)->postJson('/api/admin/productos', [
            'nombre' => 'Producto con foto',
            'precio' => '100.00',
            'categoria' => 'Mujer',
            'imagen' => '/storage/productos/original.png',
            'variantes' => [['talle' => 'M', 'color' => 'Negro', 'stock' => 4]],
        ])->assertCreated()->json('product');
        $variantId = $product['variantes'][0]['id_variante'];

        $this->withToken($token)
            ->withHeader('Accept', 'application/json')
            ->post("/api/admin/productos/{$product['id_producto']}", [
                '_method' => 'PATCH',
                'nombre' => 'Producto con foto',
                'precio' => '100.00',
                'categoria' => 'Mujer',
                'imagen' => '/storage/productos/original.png',
                'imagen_archivo' => UploadedFile::fake()->create('nueva.png', 100, 'image/png'),
                'variantes' => [
                    ['id_variante' => $variantId, 'talle' => 'M', 'color' => 'Negro', 'stock' => 4],
                ],
            ])
            ->assertOk();

        $newImage = Storage::disk('public')->allFiles('productos')[0];
        Storage::disk('public')->assertExists($newImage);
        Storage::disk('public')->assertMissing('productos/original.png');

        $this->withToken($token)
            ->patchJson("/api/admin/productos/{$product['id_producto']}", [
                'nombre' => 'Producto con foto',
                'precio' => '100.00',
                'categoria' => 'Mujer',
                'imagen' => '',
                'variantes' => [
                    ['id_variante' => $variantId, 'talle' => 'M', 'color' => 'Negro', 'stock' => 4],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('product.imagen_url', null);

        Storage::disk('public')->assertMissing($newImage);
        $this->assertDatabaseHas('productos', [
            'id_producto' => $product['id_producto'],
            'imagen' => null,
        ]);
    }

    public function test_product_update_requires_an_authenticated_admin(): void
    {
        $this->patchJson('/api/admin/productos/1', [])->assertUnauthorized();
        $this->assertDatabaseCount('productos', 0);
    }

    private function registrationData(array $overrides = []): array
    {
        return array_merge([
            'nombre' => 'Admin de prueba',
            'email' => 'admin@example.com',
            'password' => 'a-long-test-password',
            'password_confirmation' => 'a-long-test-password',
            'registration_key' => 'test-admin-key',
        ], $overrides);
    }
}

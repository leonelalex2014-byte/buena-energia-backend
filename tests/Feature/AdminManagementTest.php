<?php

namespace Tests\Feature;

use App\Models\Administrador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
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
            ->assertJsonPath('0.colors.0.image', url('/images/boxer-negro.png'))
            ->assertJsonPath('0.variantes.0.id_variante', 1);
    }

    public function test_product_creation_requires_an_authenticated_admin(): void
    {
        $this->postJson('/api/admin/productos', [])->assertUnauthorized();
        $this->assertDatabaseCount('productos', 0);
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

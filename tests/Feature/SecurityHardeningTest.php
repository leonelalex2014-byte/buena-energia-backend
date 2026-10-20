<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Administrador;
use Illuminate\Support\Facades\Hash;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_test_order_route_no_longer_exists(): void
    {
        $this->getJson('/api/test-pedido')->assertNotFound();
        $this->assertDatabaseCount('pedidos', 0);
    }

    public function test_the_image_generation_routes_no_longer_exist(): void
    {
        $this->postJson('/api/productos/generar-imagen', ['prompt' => 'remera azul'])
            ->assertNotFound();

        $this->postJson('/api/admin/productos/generar-imagen', ['prompt' => 'remera azul'])
            ->assertNotFound();
    }

    public function test_product_listing_works_with_variants(): void
    {
        $this->getJson('/api/productos')
            ->assertOk()
            ->assertJsonIsArray();
    }

    public function test_admin_registration_is_closed_once_an_administrator_exists(): void
{
    config(['admin.registration_key' => 'test-admin-key']);

    Administrador::create([
        'nombre' => 'Primero',
        'email' => 'primero@example.com',
        'contrasena' => Hash::make('a-long-test-password'),
    ]);

    $this->postJson('/api/admin/register', [
        'nombre' => 'Segundo',
        'email' => 'segundo@example.com',
        'password' => 'another-long-password',
        'password_confirmation' => 'another-long-password',
        'registration_key' => 'test-admin-key',
    ])->assertForbidden();

    $this->assertDatabaseCount('administrador', 1);
}

public function test_admin_routes_require_the_admin_ability(): void
{
    $admin = Administrador::create([
        'nombre' => 'Admin',
        'email' => 'admin@example.com',
        'contrasena' => Hash::make('a-long-test-password'),
    ]);

    $token = $admin->createToken('sin-permiso', ['lectura'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/admin/productos', [])
        ->assertForbidden();
}
}

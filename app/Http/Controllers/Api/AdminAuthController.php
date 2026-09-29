<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Administrador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminAuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('administrador', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
            'registration_key' => ['required', 'string', 'max:255'],
        ]);

        $registrationKey = config('admin.registration_key');

        if (! is_string($registrationKey) || $registrationKey === '') {
            return response()->json([
                'message' => 'El registro de administradores no está habilitado en el servidor.',
            ], 503);
        }

        if (! hash_equals($registrationKey, $validated['registration_key'])) {
            return response()->json([
                'message' => 'La clave de registro no es válida.',
            ], 403);
        }

        $administrator = Administrador::create([
            'nombre' => $validated['nombre'],
            'email' => strtolower($validated['email']),
            'contrasena' => Hash::make($validated['password']),
        ]);

        return response()->json($this->authenticatedResponse($administrator), 201);
    }

    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        $administrator = Administrador::query()
            ->where('email', strtolower($validated['email']))
            ->first();

        if (! $administrator || ! Hash::check($validated['password'], $administrator->contrasena)) {
            return response()->json([
                'message' => 'Las credenciales no son válidas.',
            ], 422);
        }

        return response()->json($this->authenticatedResponse($administrator));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->noContent();
    }

    private function authenticatedResponse(Administrador $administrator): array
    {
        return [
            'token' => $administrator->createToken('admin-panel', ['admin'], now()->addHours(12))->plainTextToken,
            'user' => [
                'id_administrador' => $administrator->id_administrador,
                'nombre' => $administrator->nombre,
                'email' => $administrator->email,
                'tipo' => 'administrador',
            ],
        ];
    }
}

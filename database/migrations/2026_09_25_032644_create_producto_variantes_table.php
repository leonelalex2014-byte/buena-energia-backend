<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('producto_variantes', function (Blueprint $table) {
    $table->id('id_variante');
    $table->foreignId('id_producto')->constrained('productos', 'id_producto')->onDelete('cascade');
    $table->string('talle'); // Ej: S, M, L, XL
    $table->string('color'); // Ej: Gris, Negro, Rosa
    $table->integer('stock'); // Stock específico para esta combinación
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_variantes');
    }
};

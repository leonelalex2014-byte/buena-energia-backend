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
        Schema::create('productos', function (Blueprint $table) {
    $table->id('id_producto');
    $table->string('nombre');
    $table->text('descripcion')->nullable();
    $table->decimal('precio', 10, 2); // Nota: aunque el diagrama dice INT, usar decimal es mejor para precios
    $table->string('categoria');
    $table->string('imagen')->nullable(); // Campo clave para mostrar el producto en la tienda
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};

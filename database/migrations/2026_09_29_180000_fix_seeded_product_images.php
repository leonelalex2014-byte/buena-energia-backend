<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('productos')
            ->where('nombre', 'Bóxer de Algodón Clásico')
            ->where('imagen', 'like', 'https://images.unsplash.com/%')
            ->update(['imagen' => '/images/boxer-gris.png']);

        DB::table('productos')
            ->where('nombre', 'Sutién Clásico')
            ->where('imagen', 'like', 'https://images.unsplash.com/%')
            ->update(['imagen' => '/images/black-bra.png']);
    }

    public function down(): void
    {
        DB::table('productos')
            ->where('nombre', 'Bóxer de Algodón Clásico')
            ->where('imagen', '/images/boxer-gris.png')
            ->update(['imagen' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1200&q=80']);

        DB::table('productos')
            ->where('nombre', 'Sutién Clásico')
            ->where('imagen', '/images/black-bra.png')
            ->update(['imagen' => 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&fit=crop&w=1200&q=80']);
    }
};

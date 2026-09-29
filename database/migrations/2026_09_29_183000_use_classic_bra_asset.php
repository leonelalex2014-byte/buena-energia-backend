<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('productos')
            ->where('nombre', 'Sutién Clásico')
            ->where('imagen', '/images/black-bra.png')
            ->update(['imagen' => '/images/bra-classic.png']);
    }

    public function down(): void
    {
        DB::table('productos')
            ->where('nombre', 'Sutién Clásico')
            ->where('imagen', '/images/bra-classic.png')
            ->update(['imagen' => '/images/black-bra.png']);
    }
};

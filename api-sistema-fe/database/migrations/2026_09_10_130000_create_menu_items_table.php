<?php
// Fase 1a (plan-modulo-menus-y-roles.md §3.1/§4/§7) — catálogo de navegación
// dinámica, compartido por giro (no por tenant), mismo criterio que `modulos`:
// editado por el equipo de desarrollo, no reconfigurado por cada tenant.
//
// `modulo_id` queda como columna nullable SIN FK real a propósito: la tabla
// `modulos`/el servicio `modulos_efectivos($tenant)` que describe
// `plan-modulo-planes-acceso.md` §2 todavía NO existe en el código (confirmado
// con grep antes de escribir esta migración — el propio plan de planes-acceso
// marca esa pieza como "trabajo genuinamente nuevo, sin conflicto", no como ya
// construida). Se deja el campo listo para cuando ese módulo exista, pero
// `MenuResolver` (Fase 1a) NO filtra por él todavía — ver comentario en
// MenuResolver::paraUsuario().
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('menu_items', function (Blueprint $table) {
            $table->id();

            $table->string('codigo')->unique();

            $table->unsignedBigInteger('parent_id')->nullable();
            $table->foreign('parent_id')->references('id')->on('menu_items')->nullOnDelete();

            $table->string('giro')->nullable();

            // Nullable, sin FK real — ver nota arriba.
            $table->unsignedBigInteger('modulo_id')->nullable();

            $table->string('permiso_requerido')->nullable();

            $table->string('label');
            $table->string('icono')->nullable();
            $table->string('ruta')->nullable();

            $table->integer('orden')->default(0);
            $table->enum('tipo', ['grupo', 'enlace']);
            $table->boolean('activo')->default(true);

            $table->timestamps();

            $table->index(['giro', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('menu_items');
    }
};

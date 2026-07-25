<?php
// Tabla de tenant (no central) — el certificado/credenciales SUNAT son
// intrínsecamente por-RUC, cada tenant tiene su propia fila. Diseño ya
// aprobado en el plan §4, sin cambios: solo se implementa acá.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sunat_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('ruc', 20)->nullable();
            $table->string('razon_social_sunat', 250)->nullable();
            $table->enum('modo', ['beta', 'produccion'])->default('beta');
            $table->text('sol_usuario')->nullable(); // encrypted (cast en el modelo)
            $table->text('sol_clave')->nullable();   // encrypted (cast en el modelo)
            // Ruta en storage PRIVADO (no el archivo en sí) — nullable a propósito,
            // no todos los tenants tienen certificado propio desde el día uno. Sin
            // certificado propio, GreenterService cae al certificado demo central
            // (ver §1c.3h del plan).
            $table->string('certificado_path', 500)->nullable();
            $table->text('certificado_password')->nullable(); // encrypted (cast en el modelo)
            $table->date('certificado_fecha_vencimiento')->nullable();
            $table->string('cuenta_bancaria_detraccion', 50)->nullable();
            $table->string('endpoint_produccion', 250)->nullable();
            $table->string('endpoint_beta', 250)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_configs');
    }
};

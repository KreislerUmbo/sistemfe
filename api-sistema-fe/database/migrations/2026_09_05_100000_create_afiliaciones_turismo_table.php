<?php
// database/migrations/2026_09_05_100000_create_afiliaciones_turismo_table.php
//
// Mejora del PDF de cotización (plan-mejora-pdf-cotizacion-cliente.md §4.3)
// — catálogo central de afiliaciones de turismo (Mincetur/Apavit/PromPerú),
// exclusivo del vertical agencia de viajes. Mismo criterio que
// proveedor_tipos (2026_07_27_150000_create_proveedor_tipos_and_temporadas_tables.php):
// central porque el logo oficial es el mismo para cualquier tenant, no
// universal porque solo aplica a giro='agencia_viajes' — de ahí que NO
// lleve columna `giro` propia (a diferencia de proveedor_tipos, acá el
// catálogo entero es específico del vertical, no un catálogo genérico
// reusado por varios).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::create('afiliaciones_turismo', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique(); // mincetur|apavit|promperu|otro
            $table->string('nombre');
            // Nullable a propósito: no se fabrica un logo oficial sin el
            // archivo real (marca registrada de terceros) — el catálogo
            // arranca sin logo_path, la franja de afiliaciones cae al
            // nombre en texto hasta que alguien cargue el archivo real.
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afiliaciones_turismo');
    }
};

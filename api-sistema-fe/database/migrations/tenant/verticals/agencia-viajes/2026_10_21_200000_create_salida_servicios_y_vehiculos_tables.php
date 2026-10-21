<?php
// database/migrations/tenant/verticals/agencia-viajes/2026_10_21_200000_create_salida_servicios_y_vehiculos_tables.php
//
// Fila 13, fase 2b (plan-tours-venta-simple-costo-real.md §3.6, D10-D13).
//
// - salida_servicios: lo que hay que reservar con cada proveedor para una
//   salida (sale del Incluye del tour; también se agregan a mano). Cantidad
//   automática = adultos + niños de la salida, salvo cantidad_manual.
//   Estado por_reservar | reservado (D11).
// - salida_vehiculos: varios por salida, con chofer, capacidad y guía
//   opcional (D12). salida_vehiculo_reservas: qué reservas lleva cada uno.
// - D13: el texto "vehículo" y el "cupo máximo" de cada salida pasan a un
//   primer vehículo. Las columnas viejas se conservan (sin uso en pantalla).
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salida_servicios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_operativa_id')->constrained('salidas_operativas')->cascadeOnDelete();
            // Servicio del Incluye del que salió (null = agregado a mano).
            $table->foreignId('paquete_plantilla_item_id')->nullable()->constrained('paquete_plantilla_items')->nullOnDelete();
            $table->string('nombre');
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->unsignedInteger('cantidad_manual')->nullable();
            $table->string('estado')->default('por_reservar');
            $table->text('notas')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE salida_servicios ADD CONSTRAINT salida_servicios_estado_check CHECK (estado IN ('por_reservar', 'reservado'))");
        // Un servicio del Incluye aparece una sola vez por salida ("Actualizar
        // desde el tour" no lo duplica).
        DB::statement('CREATE UNIQUE INDEX salida_servicios_item_unique ON salida_servicios (salida_operativa_id, paquete_plantilla_item_id) WHERE paquete_plantilla_item_id IS NOT NULL');

        Schema::create('salida_vehiculos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_operativa_id')->constrained('salidas_operativas')->cascadeOnDelete();
            // Transportista (null = vehículo propio de la agencia).
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores')->nullOnDelete();
            $table->string('descripcion')->nullable(); // "Van 14 pax · ABC-123"
            $table->string('chofer_nombre')->nullable();
            $table->string('chofer_telefono')->nullable();
            $table->unsignedSmallInteger('capacidad')->nullable();
            $table->foreignId('guia_id')->nullable()->constrained('guias')->nullOnDelete();
            $table->text('notas')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('salida_vehiculo_reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_vehiculo_id')->constrained('salida_vehiculos')->cascadeOnDelete();
            $table->foreignId('reserva_id')->constrained('reserva')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['salida_vehiculo_id', 'reserva_id']);
        });

        // D13: el vehículo de texto y el cupo de cada salida → su primer vehículo.
        $salidas = DB::table('salidas_operativas')
            ->where(fn ($q) => $q->whereNotNull('vehiculo_descripcion')->orWhereNotNull('cupo_maximo'))
            ->get(['id', 'vehiculo_descripcion', 'cupo_maximo']);
        foreach ($salidas as $salida) {
            $vehiculoId = DB::table('salida_vehiculos')->insertGetId([
                'salida_operativa_id' => $salida->id,
                'descripcion' => $salida->vehiculo_descripcion,
                'capacidad' => $salida->cupo_maximo,
                'orden' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Era el único vehículo de la salida: lleva a todas sus reservas
            // (la asignación es explícita, ver SalidaOperativa::vehiculoDeReserva()).
            $reservaIds = DB::table('reserva_items')->where('salida_operativa_id', $salida->id)
                ->distinct()->pluck('reserva_id');
            foreach ($reservaIds as $reservaId) {
                DB::table('salida_vehiculo_reservas')->insert([
                    'salida_vehiculo_id' => $vehiculoId,
                    'reserva_id' => $reservaId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Las columnas viejas (vehiculo_descripcion, cupo_maximo) nunca se
        // borraron: basta con quitar las tablas nuevas.
        Schema::dropIfExists('salida_vehiculo_reservas');
        Schema::dropIfExists('salida_vehiculos');
        Schema::dropIfExists('salida_servicios');
    }
};

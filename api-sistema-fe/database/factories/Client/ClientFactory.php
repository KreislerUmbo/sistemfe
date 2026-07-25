<?php

namespace Database\Factories\Client;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Client\Client>
 */
class ClientFactory extends Factory
{
    protected $model = \App\Models\Client\Client::class;

    public function definition(): array
    {
        $nombre = fake()->firstName() . ' ' . fake()->lastName();

        return [
            'name' => $nombre,
            'full_name' => $nombre,
            'phone' => fake()->numerify('9########'),
            'email' => fake()->unique()->safeEmail(),
            'type_client' => 1, // 1=persona natural, 2=empresa
            'type_document' => 'DNI',
            'n_document' => fake()->unique()->numerify('########'), // DNI 8 dígitos
            'cod_tipo_doc_sunat' => '1', // Catálogo 06 SUNAT: 1=DNI
            'state' => 1,
        ];
    }

    // Cliente empresa (RUC) — útil cuando el caso de prueba necesita factura, no boleta.
    public function empresa(): static
    {
        return $this->state(fn (array $attributes) => [
            'type_client' => 2,
            'type_document' => 'RUC',
            'n_document' => fake()->unique()->numerify('20#########'),
            'cod_tipo_doc_sunat' => '6', // Catálogo 06 SUNAT: 6=RUC
        ]);
    }
}

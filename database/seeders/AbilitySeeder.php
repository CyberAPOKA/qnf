<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AbilitySeeder extends Seeder
{
    /**
     * Habilidade de cada jogador (phone => ability).
     * Range: 1 (pior) a 10 (melhor).
     */
    protected array $abilities = [
        // Goleiros
        '555198249498' => 6,  // Mateus Nogueira (Zé)
        '555199562969' => 5,  // Silvio Schneider
        '555197987109' => 7,  // Ailon Ribeiro
        '555198611915' => 4,  // Lucas Fontes

        // Fixos
        '555197336092' => 4,  // Willian Guilherme
        '555199706231' => 6,  // Augusto Martins
        '555195038604' => 10, // Mateus Hensel
        '555180141784' => 8,  // Caio Drehmer
        '555195486465' => 9,  // Salenave
        '555198928569' => 7,  // Rodrigo (Beto)
        '555195864516' => 8,  // Jean Barbosa

        // Alas
        '555198407569' => 8,  // Bryan Larruscain
        '555197993094' => 4,  // Gabriel Macek
        '555199113865' => 6,  // Guto Cenci
        '555198456641' => 5,  // Nycolas Dias
        '555193103772' => 7,  // Isaque
        '555196853483' => 6,  // Motta
        '555180433478' => 5,  // Anderson
        '555196819002' => 5,  // Brayan Dorneles
        '555196272812' => 5,  // Daniel
        '555180240904' => 5,  // Deivison Mattos
        '555196304074' => 6,  // Gustavo Mendes
        '555199502165' => 5,  // João Vicente
        '555195002348' => 3,  // Pedro Pereira
        '555199233129' => 4,  // Rogério
        '555199885564' => 4,  // Roth
        '555196705353' => 1,  // Vitor (BTP)

        // Pivôs
        '555199304836' => 8,  // Christian Steffens
        '555196114386' => 8,  // Eduardo Santana
        '555199329888' => 8,  // Jair Benhur
    ];

    public function run(): void
    {
        $updated = 0;

        foreach ($this->abilities as $phone => $ability) {
            $affected = User::where('phone', $phone)->update(['ability' => $ability]);
            $updated += $affected;
        }

        $this->command->info("Habilidades atualizadas: {$updated} jogadores.");
    }
}

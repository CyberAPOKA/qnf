<?php

namespace Database\Seeders;

use App\Enums\Position;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    protected static $players = [
        // Douglas Admin 
        [
            'name'  => 'Douglas Hensel',
            'phone' => '555199236139',
            'email' => 'douglas.hensel@qnf.com',
            'role'  => 'admin',
        ],

        // Goleiros
        [
            'name'     => 'Mateus Nogueira',
            'phone'    => '555198249498',
            'email'    => 'mateus.nogueira@qnf.com',
            'position' => Position::GOALKEEPER,
        ],
        [
            'name'     => 'Silvio Schneider',
            'phone'    => '555199562969',
            'email'    => 'silvio.schneider@qnf.com',
            'position' => Position::GOALKEEPER,
        ],
        [
            'name'  => 'Ailon Ribeiro',
            'phone' => '555197987109',
            'email' => 'ailon.ribeiro@qnf.com',
            'position' => Position::GOALKEEPER,
        ],
        [
            'name'  => 'Christian Steffens',
            'phone' => '555199304836',
            'email' => 'christian.steffens@qnf.com',
        ],
        [
            'name'  => 'Augusto Martins',
            'phone' => '555199706231',
            'email' => 'augusto.martins@qnf.com',
        ],
        [
            'name'  => 'Mateus Hensel',
            'phone' => '555195038604',
            'email' => 'mateus.hensel@qnf.com',
        ],
        [
            'name'  => 'Bryan Larruscain',
            'phone' => '555198407569',
            'email' => 'bryan.larruscain@qnf.com',
        ],
        [
            'name'  => 'Caio Drehmer',
            'phone' => '555180141784',
            'email' => 'caio.drehmer@qnf.com',
        ],
        [
            'name'  => 'Eduardo Santana',
            'phone' => '555196114386',
            'email' => 'eduardo.santana@qnf.com',
        ],
        [
            'name'  => 'Gabriel Macek',
            'phone' => '555197993094',
            'email' => 'gabriel.macek@qnf.com',
        ],
        [
            'name'  => 'Guto Cenci',
            'phone' => '555199113865',
            'email' => 'guto.cenci@qnf.com',
        ],

        [
            'name'  => 'Nycolas Dias',
            'phone' => '555198456641',
            'email' => 'nycolas.dias@qnf.com',
        ],
        [
            'name'  => 'Rodrigo',
            'phone' => '555198928569',
            'email' => 'rodrigo@qnf.com',
        ],
        [
            'name'  => 'Jean Barbosa',
            'phone' => '555195864516',
            'email' => 'jean.barbosa@qnf.com',
        ],
        [
            'name'  => 'Isaque',
            'phone' => '555193103772',
            'email' => 'isaque@qnf.com',
        ],
        [
            'name'  => 'Motta',
            'phone' => '555196853483',
            'email' => 'motta@qnf.com',
        ],
        [
            'name'  => 'Anderson',
            'phone' => '555180433478',
            'email' => 'anderson@qnf.com',
        ],
        [
            'name'  => 'Brayan Dorneles',
            'phone' => '555196819002',
            'email' => 'brayan.dorneles@qnf.com',
        ],
        [
            'name'  => 'Daniel',
            'phone' => '555196272812',
            'email' => 'daniel@qnf.com',
        ],
        [
            'name'  => 'Deivison Mattos',
            'phone' => '555180240904',
            'email' => 'deivison.mattos@qnf.com',
        ],
        [
            'name'  => 'Gustavo Mendes',
            'phone' => '555196304074',
            'email' => 'gustavo.mendes@qnf.com',
        ],
        [
            'name'  => 'Jair',
            'phone' => '555199329888',
            'email' => 'jair@qnf.com',
        ],
        [
            'name'  => 'João Vicente',
            'phone' => '555199502165',
            'email' => 'joao.vicente@qnf.com',
        ],
        [
            'name'  => 'Lucas Fontes',
            'phone' => '555198611915',
            'email' => 'lucas.fontes@qnf.com',
        ],
        [
            'name'  => 'Pedro Pereira',
            'phone' => '555195002348',
            'email' => 'pedro.pereira@qnf.com',
        ],
        [
            'name'  => 'Rogério',
            'phone' => '555199233129',
            'email' => 'rogerio@qnf.com',
        ],
        [
            'name'  => 'Roth',
            'phone' => '555199885564',
            'email' => 'roth@qnf.com',
        ],
        [
            'name'  => 'Salenave',
            'phone' => '555195486465',
            'email' => 'salenave@qnf.com',
        ],
        [
            'name'  => 'Vitor',
            'phone' => '555196705353',
            'email' => 'vitor@qnf.com',
        ],
        [
            'name'  => 'Willian Guilherme',
            'phone' => '555197336092',
            'email' => 'willian.guilherme@qnf.com',
        ],
    ];

    public function run(): void
    {
        $password = Hash::make('123123123');

        foreach (static::$players as $player) {
            User::query()->firstOrCreate(
                ['phone' => $player['phone']],
                [
                    'name'     => $player['name'],
                    'email'    => $player['email'],
                    'role'     => $player['role'] ?? 'player',
                    'position' => Position::from($player['position'] ?? Position::WINGER->value),
                    'password' => $password,
                ]
            );
        }
    }
}

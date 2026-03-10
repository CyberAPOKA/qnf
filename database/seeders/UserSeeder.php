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
            'position' => Position::GOALKEEPER->value,
        ],
        [
            'name'     => 'Silvio Schneider',
            'phone'    => '555199562969',
            'email'    => 'silvio.schneider@qnf.com',
            'position' => Position::GOALKEEPER->value,
        ],
        [
            'name'  => 'Ailon Ribeiro',
            'phone' => '555197987109',
            'email' => 'ailon.ribeiro@qnf.com',
            'position' => Position::GOALKEEPER->value,
        ],
        [
            'name'  => 'Lucas Fontes',
            'phone' => '555198611915',
            'email' => 'lucas.fontes@qnf.com',
            'position' => Position::GOALKEEPER->value,
        ],

        // Fixos
        [
            'name'  => 'Willian Guilherme',
            'phone' => '555197336092',
            'email' => 'willian.guilherme@qnf.com',
            'position' => Position::FIXED->value,
        ],
        [
            'name'  => 'Augusto Martins',
            'phone' => '555199706231',
            'email' => 'augusto.martins@qnf.com',
            'position' => Position::FIXED->value,
        ],
        [
            'name'  => 'Mateus Hensel',
            'phone' => '555195038604',
            'email' => 'mateus.hensel@qnf.com',
            'position' => Position::FIXED->value,
        ],
        [
            'name'  => 'Caio Drehmer',
            'phone' => '555180141784',
            'email' => 'caio.drehmer@qnf.com',
            'position' => Position::FIXED->value,
        ],
        [
            'name'  => 'Salenave',
            'phone' => '555195486465',
            'email' => 'salenave@qnf.com',
            'position' => Position::FIXED->value,
        ],
        [
            'name'  => 'Rodrigo',
            'phone' => '555198928569',
            'email' => 'rodrigo@qnf.com',
            'position' => Position::FIXED->value,
        ],
        [
            'name'  => 'Jean Barbosa',
            'phone' => '555195864516',
            'email' => 'jean.barbosa@qnf.com',
            'position' => Position::FIXED->value,
        ],

        // Alas
        [
            'name'  => 'Bryan Larruscain',
            'phone' => '555198407569',
            'email' => 'bryan.larruscain@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Gabriel Macek',
            'phone' => '555197993094',
            'email' => 'gabriel.macek@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Guto Cenci',
            'phone' => '555199113865',
            'email' => 'guto.cenci@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Nycolas Dias',
            'phone' => '555198456641',
            'email' => 'nycolas.dias@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Isaque',
            'phone' => '555193103772',
            'email' => 'isaque@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Motta',
            'phone' => '555196853483',
            'email' => 'motta@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Anderson',
            'phone' => '555180433478',
            'email' => 'anderson@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Brayan Dorneles',
            'phone' => '555196819002',
            'email' => 'brayan.dorneles@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Daniel',
            'phone' => '555196272812',
            'email' => 'daniel@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Deivison Mattos',
            'phone' => '555180240904',
            'email' => 'deivison.mattos@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Gustavo Mendes',
            'phone' => '555196304074',
            'email' => 'gustavo.mendes@qnf.com',
            'position' => Position::FIXED->value,
        ],
        [
            'name'  => 'João Vicente',
            'phone' => '555199502165',
            'email' => 'joao.vicente@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Pedro Pereira',
            'phone' => '555195002348',
            'email' => 'pedro.pereira@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Rogério',
            'phone' => '555199233129',
            'email' => 'rogerio@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Roth',
            'phone' => '555199885564',
            'email' => 'roth@qnf.com',
            'position' => Position::WINGER->value,
        ],
        [
            'name'  => 'Vitor',
            'phone' => '555196705353',
            'email' => 'vitor@qnf.com',
            'position' => Position::WINGER->value,
        ],

        // Pivôs
        [
            'name'  => 'Christian Steffens',
            'phone' => '555199304836',
            'email' => 'christian.steffens@qnf.com',
            'position' => Position::PIVOT->value,
        ],
        [
            'name'  => 'Eduardo Santana',
            'phone' => '555196114386',
            'email' => 'eduardo.santana@qnf.com',
            'position' => Position::PIVOT->value,
        ],
        [
            'name'  => 'Jair',
            'phone' => '555199329888',
            'email' => 'jair@qnf.com',
            'position' => Position::PIVOT->value,
        ],
    ];

    public function run(): void
    {
        $password = Hash::make('qnf');

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

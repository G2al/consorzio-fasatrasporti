<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(VehicleCapacitySeeder::class);

        User::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => 'admin',
                'approval_status' => 'approved',
                'approved_at' => now(),
                'responsible_name' => 'Amministratore',
            ],
        );

        $templates = [
            'societa' => [
                'name' => 'Società',
                'documents' => [
                    'Bilancio',
                    'DURC',
                    'DURF',
                    'Incarico Medico',
                    'Idoneità Tecnico Professionale',
                    'HACCP',
                    'Albo Autotrasporti',
                    'Albo Gestore Ambientale',
                    'REN',
                    'DVR',
                    'Attestato RLS',
                    'Attestato RSPP',
                    'Attestato Primo Soccorso e Antincendio',
                    'Autorizzazione 183',
                    'Autorizzazione 852',
                    'Casellario Giudiziale',
                ],
            ],
            'dipendenti' => [
                'name' => 'Dipendenti',
                'documents' => [
                    'Unilav',
                    'Documento Identità',
                    'Patente',
                    'CF',
                    'DPI',
                    'Visita Medica',
                    'Attestato Alimentarista',
                    'Formazione',
                ],
            ],
            'veicoli' => [
                'name' => 'Veicoli',
                'documents' => [
                    'Libretto',
                    'RCA',
                    'ATP',
                    'Tachigrafo',
                ],
            ],
        ];

        foreach ($templates as $slug => $sectionData) {
            $section = Section::query()->updateOrCreate(
                ['slug' => $slug],
                ['name' => $sectionData['name']],
            );

            foreach ($sectionData['documents'] as $documentName) {
                DocumentTemplate::query()->updateOrCreate(
                    [
                        'section_id' => $section->id,
                        'name' => $documentName,
                    ],
                    [
                        'is_required' => true,
                        'description' => null,
                    ],
                );
            }
        }

        if (app()->environment('testing')) {
            return;
        }

        $demoCompanies = [
            'Alba Trasporti SRL',
            'Arel Logistica SRL',
            'Ares Trasporti SRL',
            'Autotrasporti G.M.C. SRL',
            'Autotrasporti Parenti SRL',
            'Autotrasporti LA SRL',
            'C.L.A. Trasporti e Logistica SRL',
            'Conte Autotrasporti SRL',
            'DSC Trasporti SRL',
            'Eco Cargo Italia SRL',
            'Futura Logistica SRL',
            'Global Road Service SRL',
            'Ital Move Trasporti SRL',
            'Luna Trasporti e Servizi SRL',
            'Mondo Gioielli SRL',
            'Nord Cargo SRL',
            'Orione Trasporti SRL',
            'Prima Linea Logistica SRL',
            'Road Fast Italia SRL',
            'Vega Trasporti Integrati SRL',
        ];

        foreach ($demoCompanies as $index => $companyName) {
            $number = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $email = Str::slug($companyName, '.').'.'.$number.'@example.com';

            User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $companyName,
                    'password' => 'password',
                    'role' => 'company',
                    'approval_status' => 'approved',
                    'approved_at' => now(),
                    'responsible_name' => 'Responsabile '.$number,
                    'responsible_phone' => '3330000'.$number,
                    'vat_number' => 'IT'.str_pad((string) (10000000000 + $index), 11, '0', STR_PAD_LEFT),
                ],
            );
        }
    }
}

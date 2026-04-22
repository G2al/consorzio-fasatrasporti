<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'role' => 'admin',
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
    }
}

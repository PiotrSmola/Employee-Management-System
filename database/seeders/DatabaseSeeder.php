<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed demo data only into an empty database - never on top of an
     * imported employees dataset.
     */
    public function run(): void
    {
        if (DB::table('employees')->exists()) {
            $this->command?->warn('The employees table is not empty - skipping the demo data.');

            return;
        }

        $this->call(DemoDataSeeder::class);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * The initial accounts (idempotent), then demo data - the latter only into
     * an empty database, never on top of an imported employees dataset.
     */
    public function run(): void
    {
        $this->call(UserSeeder::class);

        if (DB::table('employees')->exists()) {
            $this->command?->warn('The employees table is not empty - skipping the demo data.');

            return;
        }

        $this->call(DemoDataSeeder::class);
    }
}

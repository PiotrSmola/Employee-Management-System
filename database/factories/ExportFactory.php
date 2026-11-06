<?php

namespace Database\Factories;

use App\Enums\ExportFormat;
use App\Models\Export;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Queued exports ("My exports"):
 *
 *   Export::factory()->for($user)->create();                 // pending CSV of everybody
 *   Export::factory()->completed('exports/1_x.csv')->create();
 *
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->admin(),
            'format' => ExportFormat::Csv,
            'params' => ['selection' => ['filters' => [], 'selectAll' => true, 'ids' => []], 'columns' => []],
            'status' => Export::STATUS_PENDING,
        ];
    }

    public function completed(string $path, int $rows = 10): static
    {
        return $this->state(fn () => [
            'status' => Export::STATUS_COMPLETED,
            'file_path' => $path,
            'file_name' => basename($path),
            'file_size' => 100,
            'row_count' => $rows,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }
}

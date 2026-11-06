<?php

namespace App\Models;

use App\Support\HistoryRows;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $table = 'departments';

    protected $primaryKey = 'dept_no';

    // Natural keys from the dataset, not auto-increment.
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'dept_no',
        'dept_name',
    ];

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'dept_emp', 'dept_no', 'emp_no')
            ->wherePivot(HistoryRows::COLUMN, HistoryRows::ACTIVE)
            ->withPivot('from_date', 'to_date');
    }
}

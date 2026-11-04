<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A period an employee spent in a department (composite key emp_no + dept_no).
 * to_date = 9999-01-01 marks the current assignment.
 */
class DeptEmp extends Model
{
    use HasFactory;

    protected $table = 'dept_emp';

    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'emp_no',
        'dept_no',
        'from_date',
        'to_date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'emp_no');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'dept_no');
    }
}

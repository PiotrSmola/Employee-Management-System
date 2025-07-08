<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    use HasFactory;

    protected $table = 'departments';
    protected $primaryKey = 'dept_no';
    public $timestamps = false;
    protected $keyType = 'string';

    protected $fillable = [
        'dept_no',
        'dept_name'
    ];

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'dept_emp', 'dept_no', 'emp_no')
                    ->withPivot('from_date', 'to_date');
    }
}
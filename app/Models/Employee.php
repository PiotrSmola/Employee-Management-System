<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $table = 'employees';
    protected $primaryKey = 'emp_no';
    public $timestamps = false;

    protected $fillable = [
        'emp_no',
        'birth_date',
        'first_name',
        'last_name',
        'gender',
        'hire_date'
    ];

    public function departments()
    {
        return $this->belongsToMany(Department::class, 'dept_emp', 'emp_no', 'dept_no')
            ->withPivot('from_date', 'to_date');
    }

    public function currentDepartment()
    {
        return $this->belongsToMany(Department::class, 'dept_emp', 'emp_no', 'dept_no')
            ->withPivot('from_date', 'to_date')
            ->wherePivot('to_date', '9999-01-01')
            ->select('departments.dept_no', 'departments.dept_name');
    }

    public function titles()
    {
        return $this->hasMany(Title::class, 'emp_no');
    }

    public function currentTitle()
    {
        return $this->hasOne(Title::class, 'emp_no')
            ->where('to_date', '9999-01-01')
            ->select('emp_no', 'title');
    }

    public function salaries()
    {
        return $this->hasMany(Salary::class, 'emp_no');
    }

    public function currentSalary()
    {
        return $this->hasOne(Salary::class, 'emp_no')
            ->where('to_date', '9999-01-01')
            ->select('emp_no', 'salary');
    }

    public function scopeActive($query)
    {
        return $query->whereHas('currentDepartment');
    }
}

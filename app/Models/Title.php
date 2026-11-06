<?php

namespace App\Models;

use App\Models\Concerns\ExcludesVoidedRows;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Title extends Model
{
    use ExcludesVoidedRows;
    use HasFactory;

    protected $table = 'titles';

    // Composite primary key in the dataset; Eloquent only reads/inserts these rows.
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'emp_no',
        'title',
        'from_date',
        'to_date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'emp_no');
    }
}

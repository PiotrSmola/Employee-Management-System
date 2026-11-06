<?php

namespace App\Models\Concerns;

use App\Support\HistoryRows;
use Illuminate\Database\Eloquent\Builder;

/**
 * History models only ever see active rows (see {@see HistoryRows}).
 */
trait ExcludesVoidedRows
{
    protected static function bootExcludesVoidedRows(): void
    {
        static::addGlobalScope('active', fn (Builder $query) => HistoryRows::active($query, $query->getModel()->getTable()));
    }
}

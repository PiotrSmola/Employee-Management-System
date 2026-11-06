<?php

namespace App\View\Components;

use App\Support\FigureCache;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Tells the reader how current figures from FigureCache are: <x-figures-status :status="$figuresStatus" />
 */
class FiguresStatus extends Component
{
    public readonly bool $preparing;

    public readonly bool $stale;

    public function __construct(string $status)
    {
        $this->preparing = $status === FigureCache::PREPARING;
        $this->stale = $status === FigureCache::STALE;
    }

    public function render(): View
    {
        return view('components.figures-status');
    }
}

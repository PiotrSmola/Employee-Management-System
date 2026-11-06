<?php

namespace App\Presenters;

use App\Services\DepartmentStatistics;
use App\Support\FigureCache;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns the figures of DepartmentStatistics into what the department views
 * print: names, dates, percentages, badges. Rows keep their original fields
 * and gain the display ones; `name` is null for employees hidden from the user.
 */
class DepartmentPresenter
{
    /**
     * @param  array{departments: Collection<int, object>, figuresStatus: string}  $overview
     * @return array<string, mixed>
     */
    public function overview(array $overview): array
    {
        return [
            ...$overview,
            'departments' => $overview['departments']->map(function (object $row) {
                $row->manager = $row->manager === null ? null : $this->period($row->manager);

                return $row;
            }),
        ];
    }

    /**
     * @param  array<string, mixed>  $details  DepartmentStatistics::details()
     * @return array<string, mixed>
     */
    public function page(array $details): array
    {
        $male = $details['gender']['M'];
        $female = $details['gender']['F'];
        $people = max(1, $male + $female);
        $headcount = $details['headcount'];

        $managerHistory = $details['managerHistory']->map(fn (object $row) => $this->period($row));
        $currentManager = $managerHistory->firstWhere('isCurrent', true);
        $recentJoiners = $details['recentJoiners']->map(fn (object $row) => $this->joiner($row));
        $recentLeavers = $details['recentLeavers']->map(fn (object $row) => $this->leaver($row));

        return [
            ...$details,
            'figuresReady' => $details['figuresStatus'] !== FigureCache::PREPARING,
            'male' => $male,
            'female' => $female,
            'malePercent' => round($male / $people * 100),
            'femalePercent' => round($female / $people * 100),
            'titles' => $details['titles']->map(function (object $title) use ($headcount) {
                $title->share = $headcount > 0 ? ($title->total / $headcount) * 100 : 0;

                return $title;
            }),
            'lastDataYear' => $details['headcountByYear']->last()['year'] ?? null,
            'managerHistory' => $managerHistory,
            'currentManager' => $currentManager,
            'earliestManagerChange' => $currentManager === null
                ? null
                : Carbon::parse($currentManager->from_date)->addDay()->toDateString(),
            'today' => now()->toDateString(),
            'recentJoiners' => $recentJoiners,
            'recentLeavers' => $recentLeavers,
            'recentLists' => [
                ['title' => __('Recently joined'), 'icon' => 'box-arrow-in-right', 'rows' => $recentJoiners],
                ['title' => __('Recently left'), 'icon' => 'box-arrow-right', 'rows' => $recentLeavers],
            ],
        ];
    }

    /**
     * A date of the history; the open end of a period is "Present".
     */
    public function date(?string $value): string
    {
        return $value === null || $value === DepartmentStatistics::CURRENT
            ? __('Present')
            : Carbon::parse($value)->format('d.m.Y');
    }

    /**
     * A row with a from_date and to_date: `from`, `to`, `isCurrent`, `name`.
     */
    public function period(object $row): object
    {
        $row->name = $this->name($row);
        $row->from = $this->date($row->from_date);
        $row->to = $this->date($row->to_date);
        $row->isCurrent = $row->to_date === DepartmentStatistics::CURRENT;

        return $row;
    }

    private function joiner(object $row): object
    {
        if (! $row->hidden) {
            $row->badge = $row->hired ? __('Hired') : __('Transferred in');
            $row->badgeClass = $row->hired ? 'text-bg-success' : 'text-bg-primary';
        }

        return $this->movement($row);
    }

    private function leaver(object $row): object
    {
        if (! $row->hidden) {
            $row->badge = $row->moved_to ? __('Moved to :name', ['name' => $row->moved_to]) : __('Left the company');
            $row->badgeClass = $row->moved_to ? 'text-bg-primary' : 'text-bg-secondary';
        }

        return $this->movement($row);
    }

    /**
     * No badge for a hidden employee: nothing that says who they are or where they went.
     */
    private function movement(object $row): object
    {
        $row->name = $this->name($row);
        $row->dateLabel = $this->date($row->date);
        $row->badge ??= null;
        $row->badgeClass ??= null;

        return $row;
    }

    private function name(object $row): ?string
    {
        return $row->hidden ? null : $row->first_name.' '.$row->last_name;
    }
}

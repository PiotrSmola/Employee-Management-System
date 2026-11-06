<div class="row g-3 mb-4">
    @include('reports.partials.tile', ['gradient' => 1, 'icon' => 'people', 'value' => number_format($summary['headcount']), 'label' => __('Employees')])
    @include('reports.partials.tile', ['gradient' => 2, 'icon' => 'hourglass-split', 'value' => $summary['tenure'] === null ? '–' : __(':years yrs', ['years' => number_format($summary['tenure'], 1)]), 'label' => __('Average tenure')])
    @include('reports.partials.tile', ['gradient' => 3, 'icon' => 'gender-ambiguous', 'value' => $summary['femaleShare'] === null ? '–' : number_format($summary['femaleShare'], 1) . '%', 'label' => __('Women')])
    @include('reports.partials.tile', ['gradient' => 4, 'icon' => 'arrow-left-right', 'value' => $summary['turnover'] === null ? '–' : number_format($summary['turnover'], 1) . '%', 'label' => $summary['turnoverYear'] ? __('Turnover in :year', ['year' => $summary['turnoverYear']]) : __('Turnover')])
</div>

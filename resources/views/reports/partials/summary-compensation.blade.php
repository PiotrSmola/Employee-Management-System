<div class="row g-3 mb-4">
    @include('reports.partials.tile', ['gradient' => 1, 'icon' => 'cash-stack', 'value' => $summary['average'] === null ? '–' : '$' . number_format($summary['average']), 'label' => __('Average salary')])
    @include('reports.partials.tile', ['gradient' => 2, 'icon' => 'distribute-vertical', 'value' => $summary['median'] === null ? '–' : '$' . number_format($summary['median']), 'label' => __('Median salary')])
    @include('reports.partials.tile', ['gradient' => 3, 'icon' => 'gender-ambiguous', 'value' => $summary['payGap'] === null ? '–' : number_format($summary['payGap'], 2) . '%', 'label' => __('Gender pay gap')])
    @include('reports.partials.tile', ['gradient' => 4, 'icon' => 'people', 'value' => number_format($summary['employees']), 'label' => __('Employees with a salary')])
</div>

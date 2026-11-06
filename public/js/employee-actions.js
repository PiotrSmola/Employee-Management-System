// HR actions of the employee profile: live preview of the salary form, and the
// modal of a form that came back with validation errors opens again.
document.addEventListener('DOMContentLoaded', function() {
    const salaryForm = document.getElementById('hrSalaryForm');
    if (salaryForm) {
        const current = parseInt(salaryForm.dataset.currentSalary, 10);
        const amount = salaryForm.querySelector('[name="salary"]');
        const percent = salaryForm.querySelector('[name="percent"]');
        const preview = document.getElementById('hrSalaryPreview');
        const format = (value) => value.toLocaleString('en-US');

        const update = function() {
            const mode = salaryForm.querySelector('[name="mode"]:checked')?.value || 'amount';
            salaryForm.querySelectorAll('[data-salary-mode]').forEach(function(group) {
                const active = group.dataset.salaryMode === mode;
                group.classList.toggle('d-none', !active);
                group.querySelectorAll('input').forEach((input) => input.disabled = !active);
            });

            let next = null;
            if (mode === 'percent' && percent.value !== '' && !isNaN(current)) {
                next = Math.round(current * (100 + parseFloat(percent.value)) / 100);
            } else if (mode === 'amount' && amount.value !== '') {
                next = parseInt(amount.value, 10);
            }

            if (next === null || isNaN(next) || isNaN(current)) {
                preview.textContent = '';
                return;
            }
            const diff = next - current;
            const pct = current ? (diff / current * 100).toFixed(2) : '0';
            const sign = diff >= 0 ? '+' : '';
            preview.textContent = salaryForm.dataset.previewLabel + ': ' + format(next) + ' USD (' + sign + format(diff) + ', ' + sign + pct + ' %)';
        };

        salaryForm.querySelectorAll('[name="mode"]').forEach((radio) => radio.addEventListener('change', update));
        amount.addEventListener('input', update);
        percent.addEventListener('input', update);
        update();
    }

    const failed = document.getElementById(document.getElementById('hrActionsState')?.dataset.openModal || '');
    if (failed && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(failed).show();
    }
});

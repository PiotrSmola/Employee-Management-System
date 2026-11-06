// Searchable employee select of the "assign manager" form on the department page.
document.addEventListener('DOMContentLoaded', function() {
    const search = document.getElementById('managerSearch');
    const results = document.getElementById('managerResults');
    const empNo = document.getElementById('managerEmpNo');
    const label = document.getElementById('managerLabel');
    const form = document.getElementById('assignManagerForm');
    const { noResults, chooseFirst, sinceLabel, roleCurrent, roleFormer } = form.dataset;
    const roleLabels = { current: roleCurrent, former: roleFormer };
    let timer = null;
    let pending = null;

    const hide = () => results.classList.add('d-none');

    const render = (rows) => {
        results.replaceChildren();
        if (rows.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'list-group-item text-muted small';
            empty.textContent = noResults;
            results.append(empty);
        }
        rows.forEach(row => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action';
            const name = document.createElement('div');
            name.className = 'fw-semibold';
            name.textContent = row.name + ' (#' + row.emp_no + ')';
            const meta = document.createElement('small');
            meta.className = 'text-muted';
            meta.textContent = [row.title, row.since ? sinceLabel + ' ' + row.since : null, roleLabels[row.manager]].filter(Boolean).join(' · ');
            item.append(name, meta);
            item.addEventListener('click', () => {
                empNo.value = row.emp_no;
                label.value = row.name + ' (#' + row.emp_no + ')';
                search.value = label.value;
                search.classList.remove('is-invalid');
                hide();
            });
            results.append(item);
        });
        results.classList.remove('d-none');
    };

    const load = () => {
        // Only the latest search matters; typing cancels the one still running.
        if (pending) {
            pending.abort();
        }
        pending = new AbortController();

        fetch(search.dataset.url + '?q=' + encodeURIComponent(search.value.trim()), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            signal: pending.signal
        })
            .then(response => response.ok ? response.json() : { data: [] })
            .then(json => render(json.data || []))
            .catch(error => { if (error.name !== 'AbortError') hide(); });
    };

    search.addEventListener('input', () => {
        empNo.value = '';
        label.value = '';
        clearTimeout(timer);
        timer = setTimeout(load, 250);
    });
    search.addEventListener('focus', () => { if (empNo.value === '') load(); });
    search.addEventListener('keydown', event => { if (event.key === 'Escape') hide(); });
    document.addEventListener('click', event => {
        if (!results.contains(event.target) && event.target !== search) hide();
    });
    form.addEventListener('submit', event => {
        if (empNo.value === '') {
            event.preventDefault();
            search.classList.add('is-invalid');
            search.setCustomValidity(chooseFirst);
            search.reportValidity();
            search.setCustomValidity('');
        }
    });
});

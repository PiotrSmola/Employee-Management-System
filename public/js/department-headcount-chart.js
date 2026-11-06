// Headcount by year on the department page. Data and labels: data-* of the canvas.
document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('headcountChart');
    const rows = JSON.parse(canvas.dataset.rows);

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: rows.map(row => row.year.toString()),
            datasets: [{
                label: canvas.dataset.label,
                data: rows.map(row => row.headcount),
                backgroundColor: 'rgba(102, 126, 234, 0.7)',
                borderColor: '#667eea',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { callback: value => value.toLocaleString() }
                },
                x: {
                    title: { display: true, text: canvas.dataset.yearLabel }
                }
            }
        }
    });
});

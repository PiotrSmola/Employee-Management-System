// Average salary per year on the employee profile (data-* attributes of the canvas).
document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('salaryChart');
    const salaryData = JSON.parse(canvas.dataset.points);

    new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: salaryData.map(item => item.year.toString()),
            datasets: [{
                label: canvas.dataset.datasetLabel,
                data: salaryData.map(item => item.salary),
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return canvas.dataset.tooltipLabel + ': $' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: false,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: canvas.dataset.yearLabel
                    }
                }
            },
            elements: {
                point: {
                    hoverRadius: 8
                }
            }
        }
    });
});

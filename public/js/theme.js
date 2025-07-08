document.addEventListener("DOMContentLoaded", function () {
    const themeToggleButton = document.getElementById("theme-toggle");
    const themeIcon = document.getElementById("theme-icon");
    const html = document.documentElement;

    const savedTheme = localStorage.getItem('theme') || 'light';
    html.setAttribute("data-bs-theme", savedTheme);
    
    if (savedTheme === 'dark') {
        themeIcon.className = "bi bi-moon-stars";
    } else {
        themeIcon.className = "bi bi-sun";
    }

    if (themeToggleButton) {
        themeToggleButton.addEventListener("click", function () {
            const currentTheme = html.getAttribute("data-bs-theme");

            if (currentTheme === "dark") {
                html.setAttribute("data-bs-theme", "light");
                themeIcon.className = "bi bi-sun";
                localStorage.setItem('theme', 'light');
            } else {
                html.setAttribute("data-bs-theme", "dark");
                themeIcon.className = "bi bi-moon-stars";
                localStorage.setItem('theme', 'dark');
            }
        });
    }
});

document.addEventListener("DOMContentLoaded", function () {
    const searchToggle = document.getElementById("searchToggle");
    const searchInput = document.getElementById("searchInput");
    const searchSubmit = document.getElementById("searchSubmit");

    if (searchToggle && searchInput && searchSubmit) {
        searchToggle.addEventListener("click", function () {
            if (searchInput.style.display === "none") {
                searchInput.style.display = "block";
                searchSubmit.style.display = "block";
            } else {
                if (searchInput.value.trim() !== "") {
                    searchSubmit.click();
                }
                searchInput.style.display = "none";
                searchSubmit.style.display = "none";
            }
        });
    }
});
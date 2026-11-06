// Pages waiting for something in the background reload themselves:
// <script src="js/auto-reload.js" data-delay="5000"></script>
(function() {
    const delay = Number(document.currentScript.dataset.delay) || 5000;

    setTimeout(() => window.location.reload(), delay);
})();

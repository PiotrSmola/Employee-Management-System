{{--
    The directory keeps its filters, sort order and page in the URL (EmployeeList, #[Url(history: true)]).
    Livewire adds one history entry per changed parameter, so a change of several at once (e.g. a new
    filter, which also resets the page) would need several Back presses and pass through states that
    never existed. Entries added within the same task are merged into one.
--}}
<script>
    (function() {
        const pushState = window.history.pushState.bind(window.history);
        let pushedInThisTask = false;

        window.history.pushState = function(state, title, url) {
            if (pushedInThisTask) {
                return window.history.replaceState(state, title, url);
            }

            pushedInThisTask = true;
            setTimeout(() => pushedInThisTask = false, 0);

            return pushState(state, title, url);
        };
    })();
</script>

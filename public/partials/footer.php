</main>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => new bootstrap.Popover(el));

    // Popover bei Klick ausserhalb schliessen
    document.addEventListener('click', function(e) {
        if (!e.target.closest('[data-bs-toggle="popover"]')) {
            document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function(el) {
                var pop = bootstrap.Popover.getInstance(el);
                if (pop) pop.hide();
            });
        }
    });

    // Easy Mode (gespeichert in localStorage)
    (function() {
        var KEY = 'lm_easy';
        var toggle = document.getElementById('easyToggle');

        function applyEasy(on) {
            ['preset-card', 'accAdv'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) el.style.display = on ? 'none' : '';
            });
            if (toggle) toggle.checked = on;
        }

        var easy = localStorage.getItem(KEY) === '1';
        applyEasy(easy);

        if (toggle) {
            toggle.addEventListener('change', function() {
                localStorage.setItem(KEY, this.checked ? '1' : '0');
                applyEasy(this.checked);
            });
        }
    })();
</script>

</body>
</html>

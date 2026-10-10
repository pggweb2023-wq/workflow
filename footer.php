    </div><!-- /.content-scrollable -->
    <footer class="app-footer" aria-label="Informații aplicație">
      <span>© <?= date('Y') ?> Eneron CRM <span class="en-footer-muted">· Platformă comercială</span></span>
      <span class="en-footer-status"><span class="en-footer-dot" aria-hidden="true"></span> Panou de lucru securizat</span>
      <time id="eneron-clock" aria-label="Data și ora locală"></time>
    </footer>
  </div><!-- /.main-content -->
  <script>
    (function () {
      const clock = document.getElementById('eneron-clock');
      if (!clock) return;
      const formatter = new Intl.DateTimeFormat('ro-RO', {
        day:'2-digit', month:'2-digit', year:'numeric',
        hour:'2-digit', minute:'2-digit', second:'2-digit'
      });
      function updateClock() { clock.textContent = formatter.format(new Date()); }
      updateClock();
      setInterval(updateClock, 1000);
    })();
  </script>
</body>
</html>

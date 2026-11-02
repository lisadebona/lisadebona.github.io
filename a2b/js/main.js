(function () {
  var toggle = document.querySelector(".nav-toggle");
  var panel = document.getElementById("nav-panel");

  function closeMenu() {
    panel.classList.remove("open");
    toggle.setAttribute("aria-expanded", "false");
  }

  toggle.addEventListener("click", function () {
    var open = panel.classList.toggle("open");
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
  });

  document.querySelectorAll(".nav-drop > button").forEach(function (button) {
    button.addEventListener("click", function (event) {
      var drop = button.parentElement;
      var open = drop.classList.toggle("open");
      button.setAttribute("aria-expanded", open ? "true" : "false");
      event.stopPropagation();
    });
  });

  document.addEventListener("click", function (event) {
    if (!event.target.closest(".nav-drop")) {
      document.querySelectorAll(".nav-drop.open").forEach(function (drop) {
        drop.classList.remove("open");
        drop.querySelector("button").setAttribute("aria-expanded", "false");
      });
    }
  });

  window.addEventListener("scroll", function () {
    document.querySelectorAll(".nav-drop.open").forEach(function (drop) {
      drop.classList.remove("open");
      var button = drop.querySelector("button");
      button.setAttribute("aria-expanded", "false");
      if (drop.contains(document.activeElement)) button.blur();
    });
  }, { passive: true });

  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape") closeMenu();
  });

  panel.querySelectorAll("a").forEach(function (link) {
    link.addEventListener("click", closeMenu);
  });

  document.querySelectorAll(".acc-item button").forEach(function (button) {
    button.addEventListener("click", function () {
      var item = button.parentElement;
      var open = item.classList.toggle("open");
      button.setAttribute("aria-expanded", open ? "true" : "false");
    });
  });

  document.querySelectorAll("form").forEach(function (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      var data = new FormData(form);
      var lines = [];
      data.forEach(function (value, key) {
        if (String(value).trim()) lines.push(key + ": " + value);
      });
      var status = form.querySelector(".form-status");
      status.textContent = "Your email app is opening with this message. For help right now, call (432) 283-9048.";
      window.location.href = "mailto:a2btowingandrepairs@gmail.com?subject="
        + encodeURIComponent("A2B Towing request")
        + "&body=" + encodeURIComponent(lines.join("\n"));
    });
  });
})();

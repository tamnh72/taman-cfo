(function () {
  "use strict";

  window.dataLayer = window.dataLayer || [];

  /**
   * Central tracking hook. Replace the console.log with your analytics
   * provider call (GA4 gtag, GTM dataLayer.push, Segment analytics.track, ...).
   */
  function track(eventName, payload) {
    var data = Object.assign({ event: eventName }, payload || {});
    window.dataLayer.push(data);
    if (window.location.hostname === "localhost" || window.location.hostname === "127.0.0.1") {
      console.log("[track]", eventName, payload || {});
    }
  }

  document.getElementById("year") &&
    (document.getElementById("year").textContent = new Date().getFullYear());

  // ---------------------------------------------------------------------
  // Click tracking for elements carrying data-track
  // ---------------------------------------------------------------------
  document.addEventListener("click", function (e) {
    var el = e.target.closest("[data-track]");
    if (!el) return;
    track(el.getAttribute("data-track"), {
      location: el.getAttribute("data-track-location") || null,
      label: el.textContent.trim(),
    });

    var intent = el.getAttribute("data-form-intent");
    if (intent === "brief") {
      var briefCheckbox = document.querySelector('input[name="wants"][value="solution_brief"]');
      if (briefCheckbox) briefCheckbox.checked = true;
    }
  });

  // ---------------------------------------------------------------------
  // curriculum_view — fires once when curriculum section enters viewport
  // ---------------------------------------------------------------------
  var curriculumEl = document.querySelector("[data-curriculum]");
  if (curriculumEl && "IntersectionObserver" in window) {
    var curriculumSeen = false;
    var curriculumObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting && !curriculumSeen) {
            curriculumSeen = true;
            track("curriculum_view", {});
            curriculumObserver.disconnect();
          }
        });
      },
      { threshold: 0.3 }
    );
    curriculumObserver.observe(curriculumEl);
  }

  // ---------------------------------------------------------------------
  // faq_expand — fires when a FAQ item is opened
  // ---------------------------------------------------------------------
  var faqList = document.querySelector("[data-faq]");
  if (faqList) {
    faqList.querySelectorAll(".faq-item").forEach(function (item) {
      item.addEventListener("toggle", function () {
        if (item.open) {
          var summary = item.querySelector("summary");
          track("faq_expand", { question: summary ? summary.textContent.trim() : null });
        }
      });
    });
  }

  // ---------------------------------------------------------------------
  // scroll_depth — 25/50/75/100%
  // ---------------------------------------------------------------------
  var scrollThresholds = [25, 50, 75, 100];
  var scrollFired = {};
  function checkScrollDepth() {
    var scrollTop = window.scrollY || document.documentElement.scrollTop;
    var docHeight = document.documentElement.scrollHeight - window.innerHeight;
    if (docHeight <= 0) return;
    var pct = Math.round((scrollTop / docHeight) * 100);
    scrollThresholds.forEach(function (t) {
      if (pct >= t && !scrollFired[t]) {
        scrollFired[t] = true;
        track("scroll_depth", { percent: t });
      }
    });
  }
  var scrollTicking = false;
  window.addEventListener("scroll", function () {
    if (!scrollTicking) {
      window.requestAnimationFrame(function () {
        checkScrollDepth();
        scrollTicking = false;
      });
      scrollTicking = true;
    }
  });

  // ---------------------------------------------------------------------
  // Registration form — start / submit tracking + inline validation
  // ---------------------------------------------------------------------
  var form = document.getElementById("registration-form");
  var statusEl = document.getElementById("form-status");

  if (form) {
    var formStarted = false;
    form.addEventListener(
      "focusin",
      function () {
        if (!formStarted) {
          formStarted = true;
          track("registration_form_start", {});
        }
      },
      { once: false }
    );

    form.addEventListener("submit", function (e) {
      e.preventDefault();

      var requiredFields = form.querySelectorAll("[required]");
      var firstInvalid = null;
      requiredFields.forEach(function (field) {
        var valid = field.type === "checkbox" ? field.checked : field.value.trim() !== "";
        if (!valid && !firstInvalid) firstInvalid = field;
      });

      if (firstInvalid) {
        showStatus("error", "Vui lòng điền đầy đủ các trường bắt buộc (đánh dấu *).");
        firstInvalid.focus();
        return;
      }

      var emailField = form.querySelector('input[name="email"]');
      var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (emailField && !emailPattern.test(emailField.value.trim())) {
        showStatus("error", "Email chưa đúng định dạng. Vui lòng kiểm tra lại.");
        emailField.focus();
        return;
      }

      var payload = {
        full_name: form.full_name.value.trim(),
        email: form.email.value.trim(),
        phone: form.phone.value.trim(),
        role: form.role.value,
        industry: form.industry.value.trim(),
        ai_level: form.ai_level.value,
        use_case: form.use_case.value.trim(),
        wants: Array.from(form.querySelectorAll('input[name="wants"]:checked')).map(function (c) {
          return c.value;
        }),
      };

      track("registration_form_submit", payload);

      // TODO: gửi `payload` tới CRM/Email/Google Sheet thực tế tại đây,
      // ví dụ fetch('/api/register', { method: 'POST', body: JSON.stringify(payload) }).

      showStatus("success", "Đăng ký thành công! Đang chuyển đến trang xác nhận…");
      form.reset();

      setTimeout(function () {
        window.location.href = "thank-you.html";
      }, 900);
    });
  }

  function showStatus(type, message) {
    if (!statusEl) return;
    statusEl.textContent = message;
    statusEl.className = "form-status visible " + type;
    statusEl.scrollIntoView({ behavior: "smooth", block: "center" });
  }
})();

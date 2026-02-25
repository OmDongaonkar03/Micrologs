/**
 * Micrologs - Analytics + Error Tracking Snippet
 * Version: 1.1.0 | MIT License
 *
 * Usage:
 * <script
 *   src="https://yourdomain.com/snippet/micrologs.js"
 *   data-public-key="your_public_key"
 *   data-api-url="https://yourdomain.com"
 *   data-environment="production"
 *   async>
 * </script>
 */
(function (window, document) {
  "use strict";

  // ── Config from data attributes ───────────────────────────────
  var script = document.currentScript;
  var PUBLIC_KEY = script.getAttribute("data-public-key") || "";
  var API_URL =
    script.getAttribute("data-api-url") || script.src.split("/snippet/")[0];
  var ENVIRONMENT = script.getAttribute("data-environment") || "production";

  API_URL = API_URL.replace(/\/$/, "");

  if (!PUBLIC_KEY) {
    console.warn("[Micrologs] data-public-key is required.");
    return;
  }

  // ── Utilities ─────────────────────────────────────────────────

  function generateUUID() {
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(
      /[xy]/g,
      function (c) {
        var r = (Math.random() * 16) | 0;
        return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
      },
    );
  }

  function getCookie(name) {
    var match = document.cookie.match(
      new RegExp("(?:^|; )" + name + "=([^;]*)"),
    );
    return match ? decodeURIComponent(match[1]) : null;
  }

  function setCookie(name, value, days) {
    var expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie =
      name +
      "=" +
      encodeURIComponent(value) +
      "; expires=" +
      expires +
      "; path=/; SameSite=Lax";
  }

  function getSession(key) {
    try {
      return sessionStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function setSession(key, value) {
    try {
      sessionStorage.setItem(key, value);
    } catch (e) {}
  }

  function send(endpoint, payload) {
    if (window.fetch) {
      fetch(API_URL + endpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-API-Key": PUBLIC_KEY,
        },
        body: JSON.stringify(payload),
        keepalive: true,
      }).catch(function () {});
      return;
    }
    // XHR fallback
    var xhr = new XMLHttpRequest();
    xhr.open("POST", API_URL + endpoint, true);
    xhr.setRequestHeader("Content-Type", "application/json");
    xhr.setRequestHeader("X-API-Key", PUBLIC_KEY);
    xhr.send(JSON.stringify(payload));
  }

  // ── Visitor ID (cookie, 365 days) ─────────────────────────────

  var visitorId = getCookie("_ml_vid");
  if (!visitorId) {
    visitorId = generateUUID();
    setCookie("_ml_vid", visitorId, 365);
  }

  // ── Session token (sessionStorage, 30 min TTL) ────────────────

  var SESSION_TTL = 30 * 60 * 1000;
  var sessionToken = getSession("_ml_sid");
  var lastActivity = parseInt(getSession("_ml_last") || "0", 10);

  if (!sessionToken || Date.now() - lastActivity > SESSION_TTL) {
    sessionToken = generateUUID();
    setSession("_ml_sid", sessionToken);
  }
  setSession("_ml_last", Date.now().toString());

  // ── Fingerprint ───────────────────────────────────────────────

  function buildFingerprint() {
    var canvas = "";
    try {
      var c = document.createElement("canvas");
      var ctx = c.getContext("2d");
      ctx.textBaseline = "top";
      ctx.font = "14px Arial";
      ctx.fillText("Micrologs~", 2, 2);
      canvas = c.toDataURL().slice(-32);
    } catch (e) {}

    return [
      navigator.userAgent,
      navigator.language,
      screen.width + "x" + screen.height,
      screen.colorDepth,
      new Date().getTimezoneOffset(),
      !!window.sessionStorage,
      !!window.localStorage,
      canvas,
    ].join("|");
  }

  // ── Send pageview ─────────────────────────────────────────────

  function sendPageview() {
    var params = new URLSearchParams(window.location.search);

    send("/api/track/pageview.php", {
      url: window.location.href,
      page_title: document.title || "",
      referrer: document.referrer || "",
      visitor_id: visitorId,
      session_token: sessionToken,
      fingerprint: buildFingerprint(),
      screen_resolution: screen.width + "x" + screen.height,
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "",
      utm_source: params.get("utm_source") || "",
      utm_medium: params.get("utm_medium") || "",
      utm_campaign: params.get("utm_campaign") || "",
      utm_content: params.get("utm_content") || "",
      utm_term: params.get("utm_term") || "",
    });
  }

  // ── Error tracking ────────────────────────────────────────────

  // Prevent sending the same error multiple times in one session
  var sentErrors = {};

  function sendError(message, errorType, file, line, stack, context) {
    var dedupKey = errorType + message + (file || "") + (line || "");
    if (sentErrors[dedupKey]) return;
    sentErrors[dedupKey] = true;

    send("/api/track/error.php", {
      message: String(message).slice(0, 1024),
      error_type: errorType || "Unknown",
      file: file || window.location.href,
      line: line || null,
      stack: stack || null,
      url: window.location.href,
      severity: "error",
      environment: ENVIRONMENT,
      context: context || null,
    });
  }

  // Auto-catch runtime errors
  window.onerror = function (message, source, lineno, colno, error) {
    sendError(
      message,
      error ? error.name : "Error",
      source,
      lineno,
      error ? error.stack : null,
      null,
    );
    return false; // don't suppress default browser error handling
  };

  // Auto-catch unhandled promise rejections
  window.addEventListener("unhandledrejection", function (event) {
    var error = event.reason;
    var message = error instanceof Error ? error.message : String(error);
    sendError(
      message,
      error instanceof Error ? error.name : "UnhandledRejection",
      window.location.href,
      null,
      error instanceof Error ? error.stack : null,
      null,
    );
  });

  // ── Public API — manual error + audit sending ─────────────────
  // Usage: Micrologs.error("Payment failed", { order_id: 123 })
  // Usage: Micrologs.audit("user.login", "user@email.com", { role: "admin" })

  window.Micrologs = {
    error: function (message, context, severity) {
      send("/api/track/error.php", {
        message: String(message).slice(0, 1024),
        error_type: "ManualError",
        file: window.location.href,
        url: window.location.href,
        severity: severity || "error",
        environment: ENVIRONMENT,
        context: context || null,
      });
    },
    audit: function (action, actor, context) {
      send("/api/track/audit.php", {
        action: action,
        actor: actor || "",
        context: context || null,
      });
    },
  };

  // ── Init ──────────────────────────────────────────────────────

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", sendPageview);
  } else {
    sendPageview();
  }

  // SPA support
  var lastUrl = window.location.href;

  function onUrlChange() {
    if (window.location.href !== lastUrl) {
      lastUrl = window.location.href;
      setSession("_ml_last", Date.now().toString());
      sendPageview();
    }
  }

  var _push = history.pushState;
  var _replace = history.replaceState;

  history.pushState = function () {
    _push.apply(this, arguments);
    onUrlChange();
  };
  history.replaceState = function () {
    _replace.apply(this, arguments);
    onUrlChange();
  };

  window.addEventListener("popstate", onUrlChange);
  window.addEventListener("hashchange", onUrlChange);
})(window, document);

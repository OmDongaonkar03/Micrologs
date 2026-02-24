/**
 * Micrologs — Analytics Snippet
 * Set MICROLOGS_PUBLIC_KEY and MICROLOGS_API_URL before this script.
 * Version: 1.0.0 | MIT License
 */
(function (window, document) {
  "use strict";

  var PUBLIC_KEY = window.MICROLOGS_PUBLIC_KEY || "";
  var API_URL    = (window.MICROLOGS_API_URL || "").replace(/\/$/, "");

  if (!PUBLIC_KEY || !API_URL) {
    console.warn("[Micrologs] MICROLOGS_PUBLIC_KEY and MICROLOGS_API_URL must be set.");
    return;
  }

  // ── Utilities ─────────────────────────────────────────────────

  function generateUUID() {
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  function getCookie(name) {
    var match = document.cookie.match(new RegExp("(?:^|; )" + name + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function setCookie(name, value, days) {
    var expires = new Date(Date.now() + days * 864e5).toUTCString();
    document.cookie = name + "=" + encodeURIComponent(value) +
      "; expires=" + expires + "; path=/; SameSite=Lax";
  }

  function getSession(key) {
    try { return sessionStorage.getItem(key); } catch (e) { return null; }
  }

  function setSession(key, value) {
    try { sessionStorage.setItem(key, value); } catch (e) {}
  }

  // ── Visitor ID (cookie, 365 days) ─────────────────────────────

  var visitorId = getCookie("_ml_vid");
  if (!visitorId) {
    visitorId = generateUUID();
    setCookie("_ml_vid", visitorId, 365);
  }

  // ── Session token (sessionStorage, 30 min TTL) ────────────────

  var SESSION_TTL  = 30 * 60 * 1000;
  var sessionToken = getSession("_ml_sid");
  var lastActivity = parseInt(getSession("_ml_last") || "0", 10);

  if (!sessionToken || (Date.now() - lastActivity) > SESSION_TTL) {
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

    var payload = {
      url:               window.location.href,
      page_title:        document.title || "",
      referrer:          document.referrer || "",
      visitor_id:        visitorId,
      session_token:     sessionToken,
      fingerprint:       buildFingerprint(),
      screen_resolution: screen.width + "x" + screen.height,
      timezone:          Intl.DateTimeFormat().resolvedOptions().timeZone || "",
      utm_source:        params.get("utm_source")   || "",
      utm_medium:        params.get("utm_medium")   || "",
      utm_campaign:      params.get("utm_campaign") || "",
      utm_content:       params.get("utm_content")  || "",
      utm_term:          params.get("utm_term")     || "",
    };

    if (window.fetch) {
      fetch(API_URL + "/api/track/pageview.php", {
        method:    "POST",
        headers:   { "Content-Type": "application/json", "X-API-Key": PUBLIC_KEY },
        body:      JSON.stringify(payload),
        keepalive: true,
      }).catch(function () {});
      return;
    }

    // XHR fallback
    var xhr = new XMLHttpRequest();
    xhr.open("POST", API_URL + "/api/track/pageview.php", true);
    xhr.setRequestHeader("Content-Type", "application/json");
    xhr.setRequestHeader("X-API-Key", PUBLIC_KEY);
    xhr.send(JSON.stringify(payload));
  }

  // ── Init ──────────────────────────────────────────────────────

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", sendPageview);
  } else {
    sendPageview();
  }

  // SPA support — detect URL changes
  var lastUrl = window.location.href;

  function onUrlChange() {
    if (window.location.href !== lastUrl) {
      lastUrl = window.location.href;
      setSession("_ml_last", Date.now().toString());
      sendPageview();
    }
  }

  var _push    = history.pushState;
  var _replace = history.replaceState;

  history.pushState = function () { _push.apply(this, arguments); onUrlChange(); };
  history.replaceState = function () { _replace.apply(this, arguments); onUrlChange(); };

  window.addEventListener("popstate", onUrlChange);
  window.addEventListener("hashchange", onUrlChange);

}(window, document));
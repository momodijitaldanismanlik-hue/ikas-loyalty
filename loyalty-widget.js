(function () {
  var BACKEND = "https://ikas-loyalty.momodijital.com";

  var widget = null;
  var fetched = false;
  var currentCustomerId = null;

  function showPoints(points) {
    document.getElementById("lw-points").textContent = points + " puan";
    widget.style.display = "block";
  }

  function fetchPoints(customerId) {
    if (fetched) return;
    fetched = true;
    currentCustomerId = customerId;
    fetch(BACKEND + "/loyalty/" + customerId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var balance = (data.wallet && data.wallet.points_balance) ? data.wallet.points_balance : 0;
        showPoints(balance);
      })
      .catch(function () { widget.style.display = "none"; });
  }

  // Yöntem 1: localStorage.customerToken JWT — ikas customer ID'yi buraya koyuyor
  function tryLocalStorage() {
    try {
      var token = localStorage.getItem("customerToken");
      if (!token) return false;
      var payload = JSON.parse(atob(token.split(".")[1]));
      if (payload && payload.id) {
        fetchPoints(payload.id);
        return true;
      }
    } catch (e) {}
    return false;
  }

  // Yöntem 2: window.__NEXT_DATA__ — eski fallback
  function tryNextData() {
    try {
      var nd = window.__NEXT_DATA__;
      if (!nd) return false;
      var pp = nd.props && nd.props.pageProps;
      if (!pp) return false;

      var customer =
        (pp.customer && pp.customer.id && pp.customer) ||
        (pp.initialState && pp.initialState.customer && pp.initialState.customer.id && pp.initialState.customer) ||
        (pp.customerStore && pp.customerStore.id && pp.customerStore);

      if (customer && customer.id) {
        fetchPoints(customer.id);
        return true;
      }
    } catch (e) {}
    return false;
  }

  // Yöntem 2: IkasEvents subscription — SPA navigasyonlarında devreye girer
  function setupIkasEvents() {
    window.IkasEvents.subscribe({
      id: "loyalty_widget",
      callback: function (event) {
        var customer = event.data && event.data.customer;
        if (customer && customer.id) {
          fetchPoints(customer.id);
        }
      },
    });
  }

  function createWidget() {
    var style = document.createElement("style");
    style.textContent =
      "#loyalty-widget{" +
        "position:fixed;bottom:20px;right:20px;z-index:9999;" +
        "background:#fff;border:1px solid #e0e0e0;border-radius:12px;" +
        "padding:14px 18px;box-shadow:0 2px 12px rgba(0,0,0,0.12);" +
        "font-family:inherit;font-size:14px;color:#333;display:none;min-width:180px;" +
      "}" +
      "#loyalty-widget .lw-label{font-size:11px;color:#888;margin-bottom:2px;}" +
      "#loyalty-widget .lw-points{font-size:22px;font-weight:700;color:#1a1a1a;}" +
      "#loyalty-widget .lw-sub{font-size:11px;color:#aaa;margin-top:1px;}" +
      "#lw-redeem-btn{" +
        "margin-top:10px;width:100%;padding:7px 0;background:#1a1a1a;color:#fff;" +
        "border:none;border-radius:8px;font-size:12px;cursor:pointer;" +
      "}" +
      "#lw-redeem-btn:disabled{background:#aaa;cursor:default;}" +
      "#lw-coupon-box{" +
        "margin-top:8px;padding:6px 8px;background:#f5f5f5;border-radius:6px;" +
        "font-size:13px;font-weight:700;letter-spacing:1px;text-align:center;display:none;" +
        "cursor:pointer;color:#1a1a1a;" +
      "}" +
      "#lw-coupon-hint{font-size:10px;color:#aaa;text-align:center;margin-top:3px;display:none;}";
    document.head.appendChild(style);

    widget = document.createElement("div");
    widget.id = "loyalty-widget";
    widget.innerHTML =
      '<div class="lw-label">Sadakat Puanın</div>' +
      '<div class="lw-points" id="lw-points">...</div>' +
      '<div class="lw-sub">Her 100 TL = 5 puan</div>' +
      '<button id="lw-redeem-btn">100 Puan → 5 TL Kupon</button>' +
      '<div id="lw-coupon-box"></div>' +
      '<div id="lw-coupon-hint">Kopyalamak için tıkla</div>';
    document.body.appendChild(widget);

    document.getElementById("lw-redeem-btn").addEventListener("click", function () {
      if (!currentCustomerId) return;
      var btn = this;
      btn.disabled = true;
      btn.textContent = "Oluşturuluyor...";

      fetch(BACKEND + "/redeem", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ customerId: currentCustomerId }),
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data.ok && data.code) {
            var box = document.getElementById("lw-coupon-box");
            var hint = document.getElementById("lw-coupon-hint");
            box.textContent = data.code;
            box.style.display = "block";
            hint.style.display = "block";
            btn.style.display = "none";
            box.onclick = function () {
              navigator.clipboard && navigator.clipboard.writeText(data.code);
              hint.textContent = "Kopyalandı!";
            };
          } else {
            btn.disabled = false;
            btn.textContent = "100 Puan → 5 TL Kupon";
            alert(data.error || "Kupon oluşturulamadı.");
          }
        })
        .catch(function () {
          btn.disabled = false;
          btn.textContent = "100 Puan → 5 TL Kupon";
        });
    });
  }

  function init() {
    // DOM hazır değilse bekle
    if (!document.body) {
      document.addEventListener("DOMContentLoaded", init);
      return;
    }
    createWidget();
    // Önce localStorage JWT dene, sonra __NEXT_DATA__, çalışmazsa IkasEvents'e güven
    if (!tryLocalStorage()) tryNextData();
    setupIkasEvents();
  }

  function waitAndInit() {
    if (window.IkasEvents) { init(); }
    else { setTimeout(waitAndInit, 100); }
  }

  waitAndInit();
})();

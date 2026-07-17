from flask import Flask, request, jsonify, render_template_string
import json
import os
import random
import string
from datetime import datetime, timedelta
import firebase_admin
from firebase_admin import credentials, db

app = Flask(__name__)
VERSION_FILE = "version.json"

@app.after_request
def add_cors_headers(response):
    # Cho phép index.html trên Vercel (hoặc bất kỳ domain nào) gọi các API công khai
    if request.path.startswith("/api/"):
        response.headers["Access-Control-Allow-Origin"] = "*"
        response.headers["Access-Control-Allow-Methods"] = "GET, OPTIONS"
        response.headers["Access-Control-Allow-Headers"] = "Content-Type"
    return response

# --- Firebase Init ---
# Đọc service account JSON từ biến môi trường FIREBASE_CREDENTIALS (đặt trên Render)
firebase_creds_raw = os.environ.get("FIREBASE_CREDENTIALS")
firebase_db_url = os.environ.get("FIREBASE_DB_URL", "https://hoquocdev-server-default-rtdb.asia-southeast1.firebasedatabase.app")

if firebase_creds_raw:
    cred_dict = json.loads(firebase_creds_raw)
    cred = credentials.Certificate(cred_dict)
    firebase_admin.initialize_app(cred, {"databaseURL": firebase_db_url})
else:
    # Fallback: đọc từ file local (chỉ dùng khi test ở máy, KHÔNG dùng trên Render)
    cred = credentials.Certificate("firebase-key.json")
    firebase_admin.initialize_app(cred, {"databaseURL": firebase_db_url})

def load_keys():
    ref = db.reference("keys")
    data = ref.get()
    return data if data else {}

def save_keys(keys):
    ref = db.reference("keys")
    ref.set(keys)

def load_version():
    if not os.path.exists(VERSION_FILE):
        return {"version": "1.0", "status": "OK", "message": ""}
    with open(VERSION_FILE, "r") as f:
        return json.load(f)

def gen_key(prefix="HQD"):
    rand = ''.join(random.choices(string.ascii_uppercase + string.digits, k=12))
    return f"{prefix}-{rand[:4]}-{rand[4:8]}-{rand[8:12]}"

def check_secret(req):
    return req.args.get("secret", "") == os.environ.get("ADMIN_SECRET", "hoquocdev123")

# CHECK KEY - logic dùng chung
def check_key_logic(key, device):
    """Trả về dict kết quả kiểm tra key. Có side-effect: đăng ký thiết bị mới nếu còn slot."""
    if not key:
        return {"valid": False, "reason": "missing_key"}
    keys = load_keys()
    if key not in keys:
        return {"valid": False, "reason": "not_found"}
    info = keys[key]
    expiry = info.get("expires_at", "")
    exp_date = None
    if expiry:
        try:
            exp_date = datetime.strptime(expiry, "%Y-%m-%d %H:%M:%S")
        except ValueError:
            try:
                exp_date = datetime.strptime(expiry, "%Y-%m-%d")
            except ValueError:
                exp_date = None
        if exp_date and datetime.now() > exp_date:
            return {"valid": False, "reason": "expired", "expires_at": expiry}

    max_devices = info.get("max_devices", 1)
    devices = info.get("devices", [])
    device_registered = device in devices
    if device and not device_registered:
        if len(devices) >= max_devices:
            return {
                "valid": False, "reason": "device_limit",
                "expires_at": expiry, "max_devices": max_devices,
                "devices_used": len(devices)
            }
        devices.append(device)
        info["devices"] = devices
        keys[key] = info
        save_keys(keys)
        device_registered = True
        devices = info["devices"]

    days_left = None
    if exp_date:
        remaining = exp_date - datetime.now()
        days_left = max(0, remaining.days)

    return {
        "valid": True,
        "reason": "ok",
        "expires_at": expiry or None,
        "lifetime": expiry == "",
        "days_left": days_left,
        "max_devices": max_devices,
        "devices_used": len(devices),
        "device_registered": device_registered
    }

@app.route("/index.php")
def check_key():
    key = request.args.get("key", "").strip()
    device = request.args.get("device", "").strip()
    result = check_key_logic(key, device)
    if not result["valid"]:
        if result["reason"] == "device_limit":
            return "device_limit", 200
        return "false", 200
    if result["expires_at"]:
        return f"true|{result['expires_at']}", 200
    return "true|Vĩnh viễn", 200

# CHECK KEY - API JSON
@app.route("/api/check-key")
def api_check_key():
    key = request.args.get("key", "").strip()
    device = request.args.get("device", "").strip()
    result = check_key_logic(key, device)
    status_code = 200 if result["valid"] else 403
    return jsonify(result), status_code

# CHECK VERSION
@app.route("/pb.php")
def check_version():
    version = request.args.get("pb", "").strip()
    ver_data = load_version()
    current = ver_data.get("version", "1.0")
    status = ver_data.get("status", "OK")
    message = ver_data.get("message", "")
    if status == "MAINTAIN":
        return f"MAINTAIN|{message}", 200
    if version != current:
        return f"UPDATE|{current}|{message}", 200
    return "OK", 200

ADMIN_HTML = """
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HoQuocDev – Quản lý Key</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background: radial-gradient(circle at top, #1a1a1a 0%, #0a0a0a 60%);
    color: #eee; padding: 16px; min-height: 100vh;
  }
  h1 {
    text-align: center; margin-bottom: 20px; font-size: 22px; font-weight: 800;
    background: linear-gradient(90deg, #f90, #ffcf5c, #f90);
    background-size: 200% auto;
    -webkit-background-clip: text; background-clip: text; color: transparent;
    animation: shine 3s linear infinite, popIn .5s ease;
  }
  @keyframes shine { to { background-position: -200% center; } }
  @keyframes popIn { from { opacity: 0; transform: translateY(-10px) scale(.95); } to { opacity: 1; transform: none; } }
  @keyframes fadeInUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
  @keyframes pulseGlow { 0%,100% { text-shadow: 0 0 4px currentColor; } 50% { text-shadow: 0 0 12px currentColor; } }
  @keyframes spin { to { transform: rotate(360deg); } }
  @keyframes shake { 10%,90% { transform: translateX(-1px); } 20%,80% { transform: translateX(2px); } 30%,50%,70% { transform: translateX(-4px); } 40%,60% { transform: translateX(4px); } }

  .card {
    background: #1a1a1a; border-radius: 14px; padding: 16px; margin-bottom: 16px;
    border: 1px solid #262626; animation: fadeInUp .45s ease both;
    transition: border-color .25s, transform .25s;
  }
  .card:active { transform: scale(.995); }
  h2 { color: #f90; font-size: 15px; margin-bottom: 12px; }
  label { font-size: 13px; color: #aaa; display: block; margin-bottom: 4px; }
  select, input {
    width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #333;
    background: #111; color: #eee; font-size: 14px; margin-bottom: 10px;
    transition: border-color .2s, box-shadow .2s;
  }
  select:focus, input:focus {
    outline: none; border-color: #f90; box-shadow: 0 0 0 3px rgba(255,153,0,.2);
  }
  button {
    width: 100%; padding: 12px; border-radius: 8px; border: none;
    background: #f90; color: #000; font-weight: bold; font-size: 15px; cursor: pointer;
    transition: transform .12s ease, box-shadow .2s, filter .2s;
  }
  button:hover { filter: brightness(1.08); box-shadow: 0 4px 14px rgba(255,153,0,.25); }
  button:active { transform: scale(.96); }
  button.red { background: #e53; color: #fff; }
  button.red:hover { box-shadow: 0 4px 14px rgba(238,85,85,.3); }
  button.blue { background: #39f; color: #fff; }
  button.blue:hover { box-shadow: 0 4px 14px rgba(51,153,255,.3); }
  button.green { background: #3c3; color: #000; }
  button.green:hover { box-shadow: 0 4px 14px rgba(51,204,51,.3); }
  .key-item {
    background: #111; border-radius: 10px; padding: 12px; margin-bottom: 10px;
    border: 1px solid #2a2a2a; animation: fadeInUp .4s ease both;
    transition: border-color .25s, transform .15s;
  }
  .key-item:hover { border-color: #444; transform: translateY(-1px); }
  .key-text { font-family: monospace; font-size: 14px; color: #f90; word-break: break-all; }
  .key-info { font-size: 12px; color: #888; margin-top: 4px; }
  .countdown { font-size: 13px; color: #4f4; margin-top: 6px; font-weight: bold; transition: color .3s; }
  .countdown.urgent { color: #fb5; animation: pulseGlow 1.2s ease-in-out infinite; }
  .expired { color: #e53 !important; animation: none !important; text-shadow: none !important; }
  .actions { display: flex; gap: 8px; margin-top: 8px; }
  .actions button { padding: 8px; font-size: 12px; border-radius: 6px; }
  .msg {
    text-align: center; padding: 10px; border-radius: 8px; margin-bottom: 12px;
    color: #4f4; font-size: 14px; display: none; opacity: 0;
    transform: translateY(-8px); transition: opacity .25s, transform .25s;
  }
  .msg.show { display: block; opacity: 1; transform: translateY(0); }
  .msg.shake { animation: shake .4s; }
  .copy-btn {
    background: #333; color: #eee; font-size: 12px; padding: 6px 10px;
    border-radius: 6px; border: none; cursor: pointer; margin-top: 6px; width: auto;
    transition: background .2s;
  }
  .copy-btn:hover { background: #444; }
  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px;
    font-weight: bold; margin-left: 6px; transition: all .2s;
  }
  .badge-ok { background: #1a3a1a; color: #4f4; }
  .badge-exp { background: #3a1a1a; color: #e53; }
  .stats-row { display: flex; justify-content: space-around; text-align: center; }
  .stat-num { font-size: 24px; font-weight: bold; color: #eee; transition: color .3s; }
  .stat-label { font-size: 11px; color: #888; margin-top: 2px; }
  #searchBox, #sortSel { margin-bottom: 10px; }
  .spinner {
    width: 22px; height: 22px; margin: 20px auto; border-radius: 50%;
    border: 3px solid #333; border-top-color: #f90; animation: spin .8s linear infinite;
  }
  .empty-state { color: #666; text-align: center; padding: 20px; animation: fadeInUp .4s ease; }
  .device-toggle {
    font-size: 12px; color: #7ad; margin-top: 8px; cursor: pointer; user-select: none;
    transition: color .2s;
  }
  .device-toggle:hover { color: #9ce; }
  .device-list { margin-top: 6px; animation: fadeInUp .25s ease; }
  .device-chip {
    display: flex; align-items: center; justify-content: space-between;
    background: #1a1a1a; border: 1px solid #2a2a2a; border-radius: 6px;
    padding: 6px 10px; margin-bottom: 6px; font-size: 12px;
    transition: border-color .2s;
  }
  .device-chip:hover { border-color: #444; }
  .device-id { font-family: monospace; color: #ccc; word-break: break-all; margin-right: 8px; }
  .device-remove {
    width: auto; padding: 2px 8px; font-size: 12px; border-radius: 5px;
    background: #3a1a1a; color: #e88; border: none; cursor: pointer; flex-shrink: 0;
  }
  .device-remove:hover { background: #4a2020; }
  .device-empty { font-size: 12px; color: #666; padding: 4px 0; }
</style>
<script type="module">
  // Import the functions you need from the SDKs you need
  import { initializeApp } from "https://www.gstatic.com/firebasejs/12.16.0/firebase-app.js";
  import { getAnalytics } from "https://www.gstatic.com/firebasejs/12.16.0/firebase-analytics.js";
  // TODO: Add SDKs for Firebase products that you want to use
  // https://firebase.google.com/docs/web/setup#available-libraries

  // Your web app's Firebase configuration
  // For Firebase JS SDK v7.20.0 and later, measurementId is optional
  const firebaseConfig = {
    apiKey: "AIzaSyBRoaD3-P_jLhnZtCkr3K8a6iDk5Z56Kxs",
    authDomain: "hoquocdev-server.firebaseapp.com",
    databaseURL: "https://hoquocdev-server-default-rtdb.asia-southeast1.firebasedatabase.app",
    projectId: "hoquocdev-server",
    storageBucket: "hoquocdev-server.firebasestorage.app",
    messagingSenderId: "988530779406",
    appId: "1:988530779406:web:6e12059c6b2d32630ae35f",
    measurementId: "G-1ZDG15NNZT"
  };

  // Initialize Firebase
  const app = initializeApp(firebaseConfig);
  const analytics = getAnalytics(app);
</script>
</head>
<body>
<h1>🔑 HoQuocDev Key Manager</h1>
<div id="msg" class="msg"></div>

<div class="card" id="statsBar">
  <div class="stats-row">
    <div class="stat"><div class="stat-num" id="statTotal">0</div><div class="stat-label">Tổng key</div></div>
    <div class="stat"><div class="stat-num" style="color:#4f4" id="statActive">0</div><div class="stat-label">Còn hạn</div></div>
    <div class="stat"><div class="stat-num" style="color:#e53" id="statExpired">0</div><div class="stat-label">Hết hạn</div></div>
  </div>
</div>

<div class="card">
  <h2>➕ Tạo Key Mới</h2>
  <label>Loại thời hạn</label>
  <select id="durType" onchange="onDurChange()">
    <option value="day">📅 Theo Ngày</option>
    <option value="week">📅 Theo Tuần</option>
    <option value="month">📅 Theo Tháng</option>
    <option value="forever">♾️ Vĩnh Viễn</option>
  </select>
  <div id="durValWrap">
    <label>Số lượng (<span id="durLabel">ngày</span>)</label>
    <input type="number" id="durVal" value="30" min="1" max="365">
  </div>
  <label>Giới hạn thiết bị</label>
  <input type="number" id="maxDev" value="1" min="1" max="10">
  <label>Prefix (tuỳ chọn)</label>
  <input type="text" id="prefix" value="HQD" maxlength="6">
  <button onclick="createKey()">✨ Tạo Key</button>
</div>

<div class="card">
  <h2>📋 Danh Sách Key</h2>
  <input type="text" id="searchBox" placeholder="🔍 Tìm key..." oninput="renderKeys(cachedKeys)" style="margin-bottom:10px">
  <select id="sortSel" onchange="renderKeys(cachedKeys)" style="margin-bottom:10px">
    <option value="expiry_asc">⏳ Sắp hết hạn trước</option>
    <option value="created_desc">🆕 Mới tạo trước</option>
    <option value="expiry_desc">📅 Hạn xa nhất trước</option>
  </select>
  <button class="blue" onclick="loadKeys()" style="margin-bottom:12px">🔄 Làm mới</button>
  <div id="keyList"><div class="spinner"></div></div>
</div>

<script>
const SECRET = "{{ secret }}";

function showMsg(text, ok=true) {
  const el = document.getElementById("msg");
  el.textContent = text;
  el.style.background = ok ? "#1e3a1e" : "#3a1e1e";
  el.style.color = ok ? "#4f4" : "#e53";
  el.classList.remove("shake");
  el.classList.add("show");
  if (!ok) { void el.offsetWidth; el.classList.add("shake"); }
  clearTimeout(showMsg._t);
  showMsg._t = setTimeout(() => el.classList.remove("show"), 3000);
}

function onDurChange() {
  const val = document.getElementById("durType").value;
  const labels = { day:"ngày", week:"tuần", month:"tháng" };
  document.getElementById("durLabel").textContent = labels[val] || "";
  document.getElementById("durValWrap").style.display = val === "forever" ? "none" : "block";
}

async function createKey() {
  const durType = document.getElementById("durType").value;
  const durVal = document.getElementById("durVal").value || 1;
  const maxDev = document.getElementById("maxDev").value;
  const prefix = document.getElementById("prefix").value || "HQD";
  const res = await fetch(`/admin/add?secret=${SECRET}&prefix=${prefix}&max_devices=${maxDev}&dur_type=${durType}&dur_val=${durVal}`);
  const text = await res.text();
  showMsg(text, res.ok);
  if (res.ok) loadKeys();
}

async function deleteKey(key) {
  if (!confirm("Xoá key này?")) return;
  const res = await fetch(`/admin/delete?secret=${SECRET}&key=${encodeURIComponent(key)}`);
  showMsg(await res.text(), res.ok);
  loadKeys();
}

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, c => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  }[c]));
}

function toggleDevices(el) {
  const list = el.nextElementSibling;
  const open = list.style.display !== "none";
  list.style.display = open ? "none" : "block";
  el.textContent = open ? "📱 Xem thiết bị ▾" : "📱 Ẩn thiết bị ▴";
}

async function removeDevice(key, encodedDevice) {
  const device = decodeURIComponent(encodedDevice);
  if (!confirm(`Xoá thiết bị "${device}" khỏi key này?`)) return;
  const res = await fetch(`/admin/remove-device?secret=${SECRET}&key=${encodeURIComponent(key)}&device=${encodeURIComponent(device)}`);
  showMsg(await res.text(), res.ok);
  loadKeys();
}

async function resetKey(key) {
  if (!confirm("Reset thiết bị của key này?")) return;
  const res = await fetch(`/admin/reset?secret=${SECRET}&key=${encodeURIComponent(key)}`);
  showMsg(await res.text(), res.ok);
  loadKeys();
}

function parseExpiry(expiry) {
  // Hỗ trợ định dạng mới "YYYY-MM-DD HH:MM:SS" và định dạng cũ "YYYY-MM-DD"
  if (expiry.includes(" ")) return new Date(expiry.replace(" ", "T"));
  return new Date(expiry + "T23:59:59");
}

function countdown(expiry) {
  if (!expiry) return '<div class="countdown">♾️ Vĩnh viễn</div>';
  const exp = parseExpiry(expiry);
  const diff = exp - new Date();
  if (diff <= 0) return '<div class="countdown expired">⛔ Đã hết hạn</div>';
  const days = Math.floor(diff / 86400000);
  const hrs = Math.floor((diff % 86400000) / 3600000);
  const mins = Math.floor((diff % 3600000) / 60000);
  const secs = Math.floor((diff % 60000) / 1000);
  const urgentClass = diff < 86400000 ? " urgent" : "";
  return `<div class="countdown${urgentClass}">⏳ Còn ${days} ngày ${hrs} giờ ${mins} phút ${secs} giây</div>`;
}

function copyKey(key) {
  navigator.clipboard.writeText(key).then(() => showMsg("✅ Đã copy key!"));
}

function isExpired(expiry) {
  if (!expiry) return false;
  return parseExpiry(expiry) < new Date();
}

async function extendKey(key) {
  const durType = document.getElementById("durType").value;
  const durVal = document.getElementById("durVal").value || 1;
  if (!confirm(`Gia hạn key này thêm ${durVal} ${durType === "day" ? "ngày" : durType === "week" ? "tuần" : "tháng"}?`)) return;
  const res = await fetch(`/admin/extend?secret=${SECRET}&key=${encodeURIComponent(key)}&dur_type=${durType}&dur_val=${durVal}`);
  showMsg(await res.text(), res.ok);
  loadKeys();
}

function renderKeys(keys) {
  const el = document.getElementById("keyList");
  const search = (document.getElementById("searchBox")?.value || "").toUpperCase();
  const sortMode = document.getElementById("sortSel")?.value || "expiry_asc";
  let entries = Object.entries(keys).filter(([k]) => k.toUpperCase().includes(search));

  entries.sort((a, b) => {
    const [, va] = a, [, vb] = b;
    if (sortMode === "created_desc") {
      return (vb.created_at || "").localeCompare(va.created_at || "");
    }
    const ea = va.expires_at ? parseExpiry(va.expires_at).getTime() : Infinity;
    const eb = vb.expires_at ? parseExpiry(vb.expires_at).getTime() : Infinity;
    return sortMode === "expiry_desc" ? eb - ea : ea - eb;
  });

  if (entries.length === 0) {
    el.innerHTML = "<p class='empty-state'>Không tìm thấy key nào</p>";
  } else {
    el.innerHTML = entries.map(([k, v], i) => {
      const exp = isExpired(v.expires_at);
      const badge = exp
        ? '<span class="badge badge-exp">Hết hạn</span>'
        : '<span class="badge badge-ok">Còn hạn</span>';
      const devices = v.devices || [];
      const deviceList = devices.length
        ? devices.map(d => `
            <div class="device-chip">
              <span class="device-id">${escapeHtml(d)}</span>
              <button class="device-remove" onclick="removeDevice('${k}','${encodeURIComponent(d)}')" title="Xoá thiết bị này">✕</button>
            </div>`).join("")
        : `<div class="device-empty">Chưa có thiết bị nào</div>`;
      return `
      <div class="key-item" style="animation-delay:${Math.min(i * 0.05, 0.5)}s">
        <div class="key-text">${k} ${badge}</div>
        <button class="copy-btn" onclick="copyKey('${k}')">📋 Copy</button>
        <div class="key-info">
          📅 Hết hạn: <b>${v.expires_at || "Vĩnh viễn"}</b> &nbsp;|&nbsp;
          📱 Thiết bị: <b>${devices.length}/${v.max_devices}</b>
        </div>
        <div id="cd_${k.replace(/-/g,'_')}">${countdown(v.expires_at)}</div>
        <div class="device-toggle" onclick="toggleDevices(this)">📱 Xem thiết bị ▾</div>
        <div class="device-list" style="display:none">${deviceList}</div>
        <div class="actions">
          <button class="green" onclick="extendKey('${k}')">⏫ Gia hạn</button>
          <button class="blue" onclick="resetKey('${k}')">🔄 Reset</button>
          <button class="red" onclick="deleteKey('${k}')">🗑 Xoá</button>
        </div>
      </div>`;
    }).join("");
  }

  const total = Object.keys(keys).length;
  const expiredCount = Object.values(keys).filter(v => isExpired(v.expires_at)).length;
  animateStat("statTotal", total);
  animateStat("statActive", total - expiredCount);
  animateStat("statExpired", expiredCount);
}

function animateStat(id, target) {
  const el = document.getElementById(id);
  const from = parseInt(el.textContent, 10) || 0;
  if (from === target) return;
  const steps = 12;
  let i = 0;
  clearInterval(el._timer);
  el._timer = setInterval(() => {
    i++;
    const val = Math.round(from + (target - from) * (i / steps));
    el.textContent = val;
    if (i >= steps) { el.textContent = target; clearInterval(el._timer); }
  }, 25);
}

let cachedKeys = {};

async function loadKeys() {
  const res = await fetch(`/admin/keys?secret=${SECRET}`);
  cachedKeys = await res.json();
  renderKeys(cachedKeys);
}

// Đếm ngược realtime
setInterval(() => {
  Object.entries(cachedKeys).forEach(([k, v]) => {
    const id = "cd_" + k.replace(/-/g, '_');
    const el = document.getElementById(id);
    if (el) el.innerHTML = countdown(v.expires_at);
  });
}, 1000);

loadKeys();
</script>
</body>
</html>
"""

@app.route("/admin")
def admin_page():
    if not check_secret(request):
        return "Unauthorized", 403
    secret = request.args.get("secret")
    return render_template_string(ADMIN_HTML, secret=secret)

@app.route("/admin/add")
def admin_add():
    if not check_secret(request):
        return "Unauthorized", 403
    prefix = request.args.get("prefix", "HQD").strip().upper()
    max_dev = int(request.args.get("max_devices", 1))
    dur_type = request.args.get("dur_type", "day")
    dur_val = int(request.args.get("dur_val", 30))
    now = datetime.now()
    if dur_type == "day":
        exp = now + timedelta(days=dur_val)
    elif dur_type == "week":
        exp = now + timedelta(weeks=dur_val)
    elif dur_type == "month":
        exp = now + timedelta(days=dur_val * 30)
    else:
        exp = None
    key = gen_key(prefix)
    keys = load_keys()
    # Lưu đầy đủ giờ:phút:giây để mỗi key hết hạn đúng thời điểm tạo,
    # tránh 2 key tạo cùng ngày cùng thời hạn bị đếm ngược trùng nhau
    keys[key] = {
        "expires_at": exp.strftime("%Y-%m-%d %H:%M:%S") if exp else "",
        "max_devices": max_dev,
        "devices": [],
        "created_at": now.strftime("%Y-%m-%d %H:%M:%S"),
        "dur_type": dur_type,
        "dur_val": dur_val
    }
    save_keys(keys)
    return f"✅ Đã tạo key: {key}", 200

@app.route("/admin/extend")
def admin_extend():
    if not check_secret(request):
        return "Unauthorized", 403
    key = request.args.get("key", "").strip()
    dur_type = request.args.get("dur_type", "day")
    dur_val = int(request.args.get("dur_val", 30))
    keys = load_keys()
    if key not in keys:
        return "Key không tồn tại", 404
    info = keys[key]
    base = datetime.now()
    old_expiry = info.get("expires_at", "")
    if old_expiry:
        try:
            parsed = datetime.strptime(old_expiry, "%Y-%m-%d %H:%M:%S")
        except ValueError:
            parsed = datetime.strptime(old_expiry, "%Y-%m-%d")
        if parsed > base:
            base = parsed
    if dur_type == "day":
        new_exp = base + timedelta(days=dur_val)
    elif dur_type == "week":
        new_exp = base + timedelta(weeks=dur_val)
    elif dur_type == "month":
        new_exp = base + timedelta(days=dur_val * 30)
    else:
        new_exp = None
    info["expires_at"] = new_exp.strftime("%Y-%m-%d %H:%M:%S") if new_exp else ""
    keys[key] = info
    save_keys(keys)
    return f"✅ Đã gia hạn key: {key}", 200

@app.route("/admin/delete")
def admin_delete():
    if not check_secret(request):
        return "Unauthorized", 403
    key = request.args.get("key", "").strip()
    keys = load_keys()
    if key in keys:
        del keys[key]
        save_keys(keys)
        return f"🗑 Đã xoá key: {key}", 200
    return "Key không tồn tại", 404

@app.route("/admin/reset")
def admin_reset():
    if not check_secret(request):
        return "Unauthorized", 403
    key = request.args.get("key", "").strip()
    keys = load_keys()
    if key in keys:
        keys[key]["devices"] = []
        save_keys(keys)
        return f"🔄 Đã reset thiết bị cho key: {key}", 200
    return "Key không tồn tại", 404

@app.route("/admin/remove-device")
def admin_remove_device():
    if not check_secret(request):
        return "Unauthorized", 403
    key = request.args.get("key", "").strip()
    device = request.args.get("device", "").strip()
    keys = load_keys()
    if key not in keys:
        return "Key không tồn tại", 404
    devices = keys[key].get("devices", [])
    if device not in devices:
        return "Thiết bị không tồn tại trong key này", 404
    devices.remove(device)
    keys[key]["devices"] = devices
    save_keys(keys)
    return f"🗑 Đã xoá thiết bị khỏi key: {key}", 200

@app.route("/admin/keys")
def admin_keys():
    if not check_secret(request):
        return "Unauthorized", 403
    return jsonify(load_keys())

@app.route("/admin/version")
def admin_version():
    if not check_secret(request):
        return "Unauthorized", 403
    version = request.args.get("version", "1.0")
    status = request.args.get("status", "OK")
    message = request.args.get("message", "")
    with open(VERSION_FILE, "w") as f:
        json.dump({"version": version, "status": status, "message": message}, f)
    return f"✅ Đã cập nhật version: {version} | status: {status}", 200

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    app.run(host="0.0.0.0", port=port)

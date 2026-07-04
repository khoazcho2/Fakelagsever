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

# CHECK KEY
@app.route("/index.php")
def check_key():
    key = request.args.get("key", "").strip()
    device = request.args.get("device", "").strip()
    if not key:
        return "false", 200
    keys = load_keys()
    if key not in keys:
        return "false", 200
    info = keys[key]
    expiry = info.get("expires_at", "")
    if expiry:
        try:
            exp_date = datetime.strptime(expiry, "%Y-%m-%d")
            if datetime.now() > exp_date:
                return "false", 200
        except:
            pass
    max_devices = info.get("max_devices", 1)
    devices = info.get("devices", [])
    if device and device not in devices:
        if len(devices) >= max_devices:
            return "device_limit", 200
        devices.append(device)
        info["devices"] = devices
        keys[key] = info
        save_keys(keys)
    if expiry:
        return f"true|{expiry}", 200
    return "true|Vĩnh viễn", 200

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
  body { font-family: sans-serif; background: #0f0f0f; color: #eee; padding: 16px; }
  h1 { color: #f90; text-align: center; margin-bottom: 20px; font-size: 20px; }
  .card { background: #1a1a1a; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
  h2 { color: #f90; font-size: 15px; margin-bottom: 12px; }
  label { font-size: 13px; color: #aaa; display: block; margin-bottom: 4px; }
  select, input { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #333;
    background: #111; color: #eee; font-size: 14px; margin-bottom: 10px; }
  button { width: 100%; padding: 12px; border-radius: 8px; border: none;
    background: #f90; color: #000; font-weight: bold; font-size: 15px; cursor: pointer; }
  button.red { background: #e53; color: #fff; }
  button.blue { background: #39f; color: #fff; }
  .key-item { background: #111; border-radius: 8px; padding: 12px; margin-bottom: 10px; border: 1px solid #2a2a2a; }
  .key-text { font-family: monospace; font-size: 14px; color: #f90; word-break: break-all; }
  .key-info { font-size: 12px; color: #888; margin-top: 4px; }
  .countdown { font-size: 13px; color: #4f4; margin-top: 6px; font-weight: bold; }
  .expired { color: #e53 !important; }
  .actions { display: flex; gap: 8px; margin-top: 8px; }
  .actions button { padding: 8px; font-size: 12px; border-radius: 6px; }
  .msg { text-align: center; padding: 10px; border-radius: 8px; margin-bottom: 12px;
    color: #4f4; font-size: 14px; display: none; }
  .copy-btn { background: #333; color: #eee; font-size: 12px; padding: 6px 10px;
    border-radius: 6px; border: none; cursor: pointer; margin-top: 6px; width: auto; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; margin-left: 6px; }
  .badge-ok { background: #1a3a1a; color: #4f4; }
  .badge-exp { background: #3a1a1a; color: #e53; }
</style>
</head>
<body>
<h1>🔑 HoQuocDev Key Manager</h1>
<div id="msg" class="msg"></div>

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
  <button class="blue" onclick="loadKeys()" style="margin-bottom:12px">🔄 Làm mới</button>
  <div id="keyList">Đang tải...</div>
</div>

<script>
const SECRET = "{{ secret }}";

function showMsg(text, ok=true) {
  const el = document.getElementById("msg");
  el.textContent = text;
  el.style.display = "block";
  el.style.background = ok ? "#1e3a1e" : "#3a1e1e";
  el.style.color = ok ? "#4f4" : "#e53";
  setTimeout(() => el.style.display = "none", 3000);
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

async function resetKey(key) {
  if (!confirm("Reset thiết bị của key này?")) return;
  const res = await fetch(`/admin/reset?secret=${SECRET}&key=${encodeURIComponent(key)}`);
  showMsg(await res.text(), res.ok);
  loadKeys();
}

function countdown(expiry) {
  if (!expiry) return '<div class="countdown">♾️ Vĩnh viễn</div>';
  const exp = new Date(expiry + "T23:59:59");
  const diff = exp - new Date();
  if (diff <= 0) return '<div class="countdown expired">⛔ Đã hết hạn</div>';
  const days = Math.floor(diff / 86400000);
  const hrs = Math.floor((diff % 86400000) / 3600000);
  const mins = Math.floor((diff % 3600000) / 60000);
  const secs = Math.floor((diff % 60000) / 1000);
  return `<div class="countdown">⏳ Còn ${days} ngày ${hrs} giờ ${mins} phút ${secs} giây</div>`;
}

function copyKey(key) {
  navigator.clipboard.writeText(key).then(() => showMsg("✅ Đã copy key!"));
}

function isExpired(expiry) {
  if (!expiry) return false;
  return new Date(expiry + "T23:59:59") < new Date();
}

function renderKeys(keys) {
  const el = document.getElementById("keyList");
  const entries = Object.entries(keys);
  if (entries.length === 0) {
    el.innerHTML = "<p style='color:#666;text-align:center;padding:20px'>Chưa có key nào</p>";
    return;
  }
  el.innerHTML = entries.map(([k, v]) => {
    const exp = isExpired(v.expires_at);
    const badge = exp
      ? '<span class="badge badge-exp">Hết hạn</span>'
      : '<span class="badge badge-ok">Còn hạn</span>';
    return `
    <div class="key-item">
      <div class="key-text">${k} ${badge}</div>
      <button class="copy-btn" onclick="copyKey('${k}')">📋 Copy</button>
      <div class="key-info">
        📅 Hết hạn: <b>${v.expires_at || "Vĩnh viễn"}</b> &nbsp;|&nbsp;
        📱 Thiết bị: <b>${(v.devices||[]).length}/${v.max_devices}</b>
      </div>
      <div id="cd_${k.replace(/-/g,'_')}">${countdown(v.expires_at)}</div>
      <div class="actions">
        <button class="blue" onclick="resetKey('${k}')">🔄 Reset</button>
        <button class="red" onclick="deleteKey('${k}')">🗑 Xoá</button>
      </div>
    </div>`;
  }).join("");
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
    keys[key] = {
        "expires_at": exp.strftime("%Y-%m-%d") if exp else "",
        "max_devices": max_dev,
        "devices": [],
        "created_at": now.strftime("%Y-%m-%d")
    }
    save_keys(keys)
    return f"✅ Đã tạo key: {key}", 200

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

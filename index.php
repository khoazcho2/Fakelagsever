<?php
// ============================================================
// index.php - Trang chủ công khai, liệt kê các game đang mở cấp
// key. User bấm "Lấy key miễn phí" -> sang getkey.php?game=slug
// ============================================================
require_once __DIR__ . '/config.php';

$games = array_filter(get_games(), function ($g) { return (bool)$g['enabled']; });
$region = detect_region();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lấy Key Game</title>
<style>
*{box-sizing:border-box}
body{font-family:-apple-system,Arial,sans-serif;max-width:420px;margin:0 auto;background:#0f1115;color:#eee;text-align:center;padding:32px 16px}
h1{font-size:22px;margin:6px 0}
.provider{color:#e08a4f}
.sub{font-size:13px;color:#999;margin-bottom:18px}
.badges{display:flex;justify-content:center;gap:14px;font-size:12px;color:#bbb;margin-bottom:26px;flex-wrap:wrap}
h2.section{font-size:16px;text-align:left;margin:0 0 10px}
.card{background:#181b22;border-radius:14px;padding:22px 16px;margin-bottom:14px;text-align:center}
.icon{font-size:34px;margin-bottom:6px}
.gname{font-weight:bold;font-size:16px}
.price{color:#999;font-size:13px;margin:4px 0 10px}
.free-badge{display:inline-block;background:#1e4620;color:#4caf50;font-size:12px;padding:3px 10px;border-radius:12px;margin-bottom:10px}
a.btn{display:block;background:#e07a4f;color:#fff;text-decoration:none;font-weight:bold;padding:12px;border-radius:10px}
.hops{font-size:12px;color:#888;margin-top:8px}
.empty{color:#888;font-size:14px;margin-top:40px}
</style>
</head>
<body>
<p class="sub">Cung cấp bởi <span class="provider">hoquoc</span></p>
<h1>Lấy Key Game</h1>
<p class="sub">Nhận key game chất lượng từ hoquoc — nhanh chóng, uy tín.</p>
<div class="badges">
    <span>⚡ Giao key tức thì</span>
    <span>🛡️ An toàn &amp; uy tín</span>
    <span>🔄 Cập nhật liên tục</span>
</div>

<h2 class="section">Danh sách game</h2>

<?php if (empty($games)): ?>
    <p class="empty">Hiện chưa có game nào mở cấp key.</p>
<?php else: foreach ($games as $g):
    $hops = $region === 'intl' ? $g['intl_hops'] : $g['vn_hops'];
?>
    <div class="card">
        <div class="icon"><?= htmlspecialchars($g['icon']) ?></div>
        <div class="gname"><?= htmlspecialchars($g['name']) ?></div>
        <div class="price">Giá từ 0 đ</div>
        <div class="free-badge">🎁 Có key free</div>
        <a class="btn" href="getkey.php?game=<?= urlencode($g['slug']) ?>">⚡ Lấy key miễn phí</a>
        <div class="hops">Vượt <?= (int)$hops ?> lần link rút gọn</div>
    </div>
<?php endforeach; endif; ?>

</body>
</html>

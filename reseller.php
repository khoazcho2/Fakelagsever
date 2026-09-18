<?php
// Trang reseller đã được gỡ bỏ khỏi hệ thống.
// Redirect về trang chủ (không set 404 vì header Location ghi đè thành 302)
header('Location: index.php');
exit;

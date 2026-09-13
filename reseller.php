<?php
// Trang reseller đã được gỡ bỏ khỏi hệ thống.
http_response_code(404);
header('Location: index.php');
exit;

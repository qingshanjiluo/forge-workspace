<?php
require_once '../config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');

$url = trim($_GET['url'] ?? '');
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => '无效的URL']);
    exit;
}

// SSRF 防护：阻断内网地址
$host = parse_url($url, PHP_URL_HOST);
$ip = gethostbyname($host);
if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
    echo json_encode(['error' => '不允许访问内网地址']);
    exit;
}

$cache_file = sys_get_temp_dir() . '/link_preview_' . md5($url) . '.cache';
if (file_exists($cache_file) && time() - filemtime($cache_file) < 3600) {
    echo file_get_contents($cache_file);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$html || $httpCode >= 400) {
    echo json_encode(['error' => '无法访问该链接', 'url' => $url]);
    exit;
}

$title = '';
$description = '';
$image = '';
$favicon = '';
$domain = parse_url($url, PHP_URL_HOST);

if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) $title = trim(strip_tags($m[1]));
if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) $title = $m[1];
if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) $description = $m[1];
if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) $description = $m[1];
if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\'](.*?)["\']/si', $html, $m)) $image = $m[1];
if (preg_match('/<link[^>]+rel=["\']icon["\'][^>]+href=["\'](.*?)["\']/si', $html, $m)) $favicon = $m[1];
if (!$favicon && preg_match('/<link[^>]+rel=["\']shortcut icon["\'][^>]+href=["\'](.*?)["\']/si', $html, $m)) $favicon = $m[1];
if (!$favicon) $favicon = 'https://' . $domain . '/favicon.ico';
if ($image && strpos($image, 'http') !== 0) $image = (parse_url($url, PHP_URL_SCHEME) ?: 'https') . '://' . $domain . '/' . ltrim($image, '/');

$title = mb_substr($title, 0, 200);
$description = mb_substr($description, 0, 500);

$result = json_encode([
    'url' => $url,
    'domain' => $domain,
    'title' => $title ?: $domain,
    'description' => $description ?: '',
    'image' => $image ?: '',
    'favicon' => $favicon,
], JSON_UNESCAPED_UNICODE);

file_put_contents($cache_file, $result);
echo $result;

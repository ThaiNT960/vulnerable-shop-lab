<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function path(): string
{
    return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
}

function go(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function method_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . h(token()) . '">';
}

function check_csrf(): void
{
    if (!hash_equals(token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('CSRF token không hợp lệ.');
    }
}

function current_user(PDO $db): ?array
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if (!$id) {
        return null;
    }
    $query = $db->prepare('SELECT id,username,email,role,balance FROM users WHERE id=?');
    $query->execute([$id]);
    return $query->fetch() ?: null;
}

function require_user(PDO $db): array
{
    $user = current_user($db);
    if (!$user) {
        go('/login');
    }
    return $user;
}

function require_admin(PDO $db): array
{
    $user = require_user($db);
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        page('Không có quyền', '<div class="notice danger"><h1>403 · Bạn không có quyền truy cập</h1><p>Trang quản trị chỉ dành cho quản trị viên.</p><a class="button" href="/">Về cửa hàng</a></div>', $user);
        exit;
    }
    return $user;
}

function flash(string $message, string $kind = 'ok'): void
{
    $_SESSION['flash'] = [$message, $kind];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function money(int|string $amount): string
{
    return number_format((int)$amount, 0, ',', '.') . ' xu';
}

function input(string $key, int $max = 255): string
{
    return substr(trim((string)($_POST[$key] ?? '')), 0, $max);
}

function get_product(PDO $db, int $id): ?array
{
    $q = $db->prepare('SELECT p.*,u.username AS seller FROM products p JOIN users u ON u.id=p.owner_id WHERE p.id=?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function product_image(array $product): string
{
    return str_starts_with($product['image'], 'uploads/') ? '/' . $product['image'] : '/image?file=' . rawurlencode($product['image']);
}

function card(array $p): string
{
    $price = money((int)$p['price']);
    $name = h($p['name']);
    $img = h(product_image($p));
    $id = (int)$p['id'];
    return "<article class=\"product-card\">"
        . "<a class=\"product-visual\" href=\"/product?id=$id\"><img src=\"$img\" alt=\"$name\"></a>"
        . "<h3>$name</h3>"
        . "<div class=\"product-meta\"><span>★ ★ ★ ★ ☆</span><strong>$price</strong></div>"
        . "<a class=\"button dark\" href=\"/product?id=$id\">Xem chi tiết</a>"
        . '</article>';
}

function page(string $title, string $content, ?array $user = null, string $eyebrow = 'ONLINE SHOP'): void
{
    $flash = take_flash();
    $nav = $user
        ? '<a href="/account">Tài khoản</a>'
            . '<a href="/orders">Đơn hàng</a>'
            . '<a href="/sell">Đăng bán</a>'
            . '<a href="/cart">Giỏ hàng (' . array_sum($_SESSION['cart'] ?? []) . ')</a>'
        : '<a href="/login">Đăng nhập</a><a href="/register">Đăng ký</a>';
    if ($user && $user['role'] === 'admin') {
        $nav .= '<a href="/admin">Quản trị</a>';
    }
    $message = $flash
        ? '<div class="flash ' . h($flash[1]) . '">' . h($flash[0]) . '</div>'
        : '';
    $logout = $user
        ? '<form class="logout" method="post" action="/logout">'
            . csrf_input() . '<button>Đăng xuất</button></form>'
        : '';

    echo '<!doctype html><html lang="vi"><head>'
        . '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' · Online Shop</title>'
        . '<link rel="stylesheet" href="/assets/style.css">'
        . '</head><body>';
    echo '<header class="site-header">'
        . '<div class="shell top-inner">'
        . '<a class="brand" href="/">'
        . '<span class="brand-name">ONLINE</span><span class="brand-shop">SHOP</span>'
        . '</a><div class="page-name">' . h($title) . '</div></div>'
        . '<div class="orange-rule"></div>'
        . '<div class="shell nav-row">'
        . '<span class="nav-caption">' . h($eyebrow) . '</span>'
        . '<nav><a href="/">Trang chủ</a>' . $nav . $logout . '</nav>'
        . '</div></header>';
    echo '<main class="shell">' . $message . $content . '</main>'
        . '<footer><div class="shell footer-inner">'
        . '<strong>ONLINE SHOP</strong><span>© 2026 Online Shop</span>'
        . '</div></footer></body></html>';
}

function fail(string $message, int $code = 400): never
{
    http_response_code($code);
    page(
        'Yêu cầu không hợp lệ',
        '<div class="notice danger"><h1>' . h($message)
            . '</h1><a class="button" href="/">Về trang chủ</a></div>',
        $GLOBALS['db'] ? current_user($GLOBALS['db']) : null
    );
    exit;
}

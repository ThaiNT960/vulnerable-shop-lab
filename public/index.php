<?php

declare(strict_types=1);

require_once (getenv('SHOP_APP_DIR') ?: '/var/www/shop-app') . '/bootstrap.php';

if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'))) {
    return false;
}

$route = path();
$user = current_user($db);

try {
    switch ($route) {
        case '/':
            home($db, $user);
            break;
        case '/image':
            image_endpoint();
            break;
        case '/login':
            login($db, $user);
            break;
        case '/register':
            register($db, $user);
            break;
        case '/logout':
            logout();
            break;
        case '/account':
            account($db);
            break;
        case '/account/update':
            account_update($db);
            break;
        case '/wallet/transfer':
            transfer($db);
            break;
        case '/product':
            product($db, $user);
            break;
        case '/comment/add':
            comment_add($db);
            break;
        case '/sell':
            sell($db);
            break;
        case '/my-products':
            my_products($db);
            break;
        case '/product/edit':
            product_edit($db);
            break;
        case '/cart':
            cart($db, $user);
            break;
        case '/cart/add':
            cart_add($db);
            break;
        case '/cart/remove':
            cart_remove();
            break;
        case '/checkout':
            checkout($db);
            break;
        case '/orders':
            orders($db);
            break;
        case '/orders/show':
            order_show($db);
            break;
        case '/admin':
            admin($db);
            break;
        case '/admin/action':
            admin_action($db);
            break;
        default:
            fail('Không tìm thấy trang.', 404);
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Dữ liệu không hợp lệ hoặc thao tác không thực hiện được.');
}

function home(PDO $db, ?array $user): void
{
    $category = (string)($_GET['category'] ?? '');
    $categories = $db->query("SELECT DISTINCT category FROM products WHERE status='published' ORDER BY category")
                     ->fetchAll(PDO::FETCH_COLUMN);

    $sql = "SELECT id, name, description, price, image, category FROM products WHERE status='published'";
    if ($category !== '') {
        $sql .= " AND category = '" . $category . "'";
    }
    $sql .= ' ORDER BY id DESC';
    $products = $db->query($sql)->fetchAll();

    ob_start();
    ?>
    <section class="shop-logo">
        <span>WE LIKE TO</span>
        <strong>SHOP</strong>
        <div class="hanger">?</div>
    </section>

    <section class="catalog" id="products">
        <div class="filters">
            <a class="<?= $category === '' ? 'active' : '' ?>" href="/">Tất cả</a>
            <?php foreach ($categories as $cat): ?>
                <a class="<?= $category === $cat ? 'active' : '' ?>" href="/?category=<?= urlencode($cat) ?>">
                    <?= h($cat) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="product-grid">
            <?php foreach ($products as $p): ?>
                <?= card($p) ?>
            <?php endforeach; ?>
        </div>

        <?php if (!$products): ?>
            <div class="empty">Chưa có sản phẩm trong danh mục này.</div>
        <?php endif; ?>
    </section>
    <?php
    page('Trang chủ', ob_get_clean(), $user);
}

function image_endpoint(): void
{
    $name = (string)($_GET['file'] ?? '');
    $file = __DIR__ . '/assets/images/' . $name;

    if (!is_file($file)) {
        http_response_code(404);
        exit('Image not found');
    }

    header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
    readfile($file);
}

function login(PDO $db, ?array $user): void
{
    if ($user) {
        go('/account');
    }

    $error = '';
    if (method_post()) {
        check_csrf();
        $q = $db->prepare('SELECT * FROM users WHERE username = ?');
        $q->execute([input('username')]);
        $found = $q->fetch();

        if ($found && password_verify((string)($_POST['password'] ?? ''), $found['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$found['id'];
            flash('Chào mừng trở lại, ' . $found['username'] . '!');
            go('/account');
        }
        $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
    }

    ob_start();
    ?>
    <div class="auth-layout single">
        <div class="auth-panel">
            <h1>Đăng nhập</h1>
            <?php if ($error): ?>
                <div class="inline-error"><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post" class="form-stack">
                <?= csrf_input() ?>
                <label>
                    Tên đăng nhập
                    <input name="username" required autocomplete="username">
                </label>
                <label>
                    Mật khẩu
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                <button class="button dark">Đăng nhập</button>
            </form>
            <p class="auth-footer">Chưa có tài khoản? <a href="/register">Đăng ký</a></p>
        </div>
    </div>
    <?php
    page('Đăng nhập', ob_get_clean());
}

function register(PDO $db, ?array $user): void
{
    if ($user) {
        go('/account');
    }

    $error = '';
    if (method_post()) {
        check_csrf();
        $name = input('username', 40);
        $email = input('email', 120);
        $password = (string)($_POST['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{3,40}$/', $name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $error = 'Tên dùng 3–40 ký tự chữ/số; email hợp lệ; mật khẩu tối thiểu 8 ký tự.';
        } elseif (email_in_use($db, $email)) {
            $error = 'Email này đã được sử dụng.';
        } else {
            try {
                $q = $db->prepare("INSERT INTO users (username, password_hash, email, role, balance) VALUES (?, ?, ?, 'user', 200)");
                $q->execute([$name, password_hash($password, PASSWORD_DEFAULT), $email]);
                $_SESSION['user_id'] = (int)$db->lastInsertId();
                session_regenerate_id(true);
                flash('Đã tạo tài khoản. Bạn được tặng 200 xu.');
                go('/account');
            } catch (PDOException $e) {
                $error = email_in_use($db, $email) ? 'Email này đã được sử dụng.' : 'Tên đăng nhập đã được sử dụng.';
            }
        }
    }

    ob_start();
    ?>
    <div class="auth-layout single">
        <div class="auth-panel">
            <h1>Đăng ký</h1>
            <?php if ($error): ?>
                <div class="inline-error"><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post" class="form-stack">
                <?= csrf_input() ?>
                <label>
                    Tên đăng nhập
                    <input name="username" required autocomplete="username">
                </label>
                <label>
                    Email
                    <input type="email" name="email" required autocomplete="email">
                </label>
                <label>
                    Mật khẩu
                    <input type="password" name="password" minlength="8" required autocomplete="new-password">
                </label>
                <button class="button dark">Tạo tài khoản</button>
            </form>
            <p class="auth-footer">Đã có tài khoản? <a href="/login">Đăng nhập</a></p>
        </div>
    </div>
    <?php
    page('Đăng ký', ob_get_clean());
}

function logout(): void
{
    if (!method_post()) {
        fail('Phương thức không hợp lệ.', 405);
    }
    check_csrf();
    $_SESSION = [];
    session_regenerate_id(true);
    flash('Bạn đã đăng xuất.');
    go('/');
}

function account(PDO $db): void
{
    $user = require_user($db);
    $q = $db->prepare('SELECT id, amount, created_at FROM transfers WHERE sender_id = ? ORDER BY id DESC LIMIT 6');
    $q->execute([$user['id']]);
    $history = $q->fetchAll();

    ob_start();
    ?>
    <section class="page-intro compact">
        <span class="eyebrow">MY ACCOUNT / <?= h(strtoupper($user['role'])) ?></span>
        <h1>Xin chào, <?= h($user['username']) ?><span class="title-dot">.</span></h1>
        <p>Quản lý hồ sơ, sản phẩm và số xu của bạn.</p>
    </section>

    <div class="dashboard-grid">
        <section class="surface">
            <div class="surface-head">
                <span class="eyebrow">HỒ SƠ CÁ NHÂN</span>
                <h2>Thông tin tài khoản</h2>
            </div>
            <p class="muted">Tên đăng nhập: <b><?= h($user['username']) ?></b></p>
            <form class="form-stack" method="post" action="/account/update">
                <?= csrf_input() ?>
                <label>
                    Email
                    <input type="email" name="email" value="<?= h($user['email']) ?>" required>
                </label>
                <button class="button dark">Lưu thay đổi <span>→</span></button>
            </form>
        </section>

        <section class="surface wallet">
            <div class="surface-head">
                <span class="eyebrow">VÍ XU</span>
                <h2>Số dư hiện tại</h2>
            </div>
            <strong class="balance"><?= money($user['balance']) ?></strong>
            <p>Chuyển xu cho một thành viên khác.</p>
            <form class="form-stack" method="get" action="/wallet/transfer">
                <label>
                    Người nhận
                    <input name="to" placeholder="user2" required>
                </label>
                <label>
                    Số xu
                    <input type="number" name="amount" min="1" max="1000" value="50" required>
                </label>
                <button class="button orange">Chuyển xu <span>↗</span></button>
            </form>
            <div class="history">
                <h3>Giao dịch gần đây</h3>
                <?php foreach ($history as $item): ?>
                    <div>
                        <span>#<?= (int)$item['id'] ?> · <?= h($item['created_at']) ?></span>
                        <b>−<?= money($item['amount']) ?></b>
                    </div>
                <?php endforeach; ?>
                <?php if (!$history): ?>
                    <small>Chưa có giao dịch.</small>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="quick-links">
        <a href="/orders">Đơn hàng của tôi <span>↗</span></a>
        <a href="/my-products">Sản phẩm đã đăng <span>↗</span></a>
        <a href="/sell">Đăng sản phẩm mới <span>↗</span></a>
        <?php if ($user['role'] === 'admin'): ?>
            <a href="/admin">Trang quản trị <span>↗</span></a>
        <?php endif; ?>
    </div>
    <?php
    page('Tài khoản của tôi', ob_get_clean(), $user);
}

function account_update(PDO $db): void
{
    $user = require_user($db);
    if (!method_post()) {
        fail('Phương thức không hợp lệ.', 405);
    }
    check_csrf();

    $email = input('email', 120);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Email không hợp lệ.');
    }
    if (email_in_use($db, $email, (int)$user['id'])) {
        email_conflict();
    }

    $role = array_key_exists('role', $_POST) ? input('role', 20) : $user['role'];
    $q = $db->prepare('UPDATE users SET email = ?, role = ? WHERE id = ?');
    try {
        $q->execute([$email, $role, $user['id']]);
    } catch (PDOException $e) {
        if (email_in_use($db, $email, (int)$user['id'])) {
            email_conflict();
        }
        throw $e;
    }

    flash('Đã cập nhật hồ sơ.');
    header('Location: /account', true, 303);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'username' => $user['username'],
        'email' => $email,
        'role' => $role,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function email_in_use(PDO $db, string $email, int $exceptId = 0): bool
{
    $q = $db->prepare('SELECT 1 FROM users WHERE email = ? COLLATE NOCASE AND id <> ? LIMIT 1');
    $q->execute([$email, $exceptId]);
    return (bool)$q->fetchColumn();
}

function email_conflict(): never
{
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html')) {
        flash('Email này đã được sử dụng.', 'danger');
        go('/account');
    }

    http_response_code(409);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":"EMAIL_ALREADY_IN_USE"}';
    exit;
}

function transfer(PDO $db): void
{
    $user = require_user($db);
    $to = trim((string)($_GET['to'] ?? ''));
    $amount = filter_var($_GET['amount'] ?? null, FILTER_VALIDATE_INT);

    if (!$to || $amount === false || $amount < 1 || $amount > 1000) {
        fail('Giao dịch không hợp lệ.');
    }

    $db->beginTransaction();
    try {
        $q = $db->prepare('SELECT id, balance FROM users WHERE username = ?');
        $q->execute([$to]);
        $recipient = $q->fetch();

        $q = $db->prepare('SELECT balance FROM users WHERE id = ?');
        $q->execute([$user['id']]);
        $balance = (int)$q->fetchColumn();

        if (!$recipient || (int)$recipient['id'] === (int)$user['id'] || $balance < $amount) {
            $db->rollBack();
            fail('Không tìm thấy người nhận hoặc số dư không đủ.');
        }

        $q = $db->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
        $q->execute([$amount, $user['id']]);

        $q = $db->prepare('UPDATE users SET balance = balance + ? WHERE id = ?');
        $q->execute([$amount, $recipient['id']]);

        $q = $db->prepare('INSERT INTO transfers (sender_id, recipient_id, amount) VALUES (?, ?, ?)');
        $q->execute([$user['id'], $recipient['id'], $amount]);

        $db->commit();
        flash('Đã chuyển ' . money($amount) . ' cho ' . $to . '.');
        go('/account');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function product(PDO $db, ?array $user): void
{
    $id = (int)($_GET['id'] ?? 0);
    $p = get_product($db, $id);

    if (!$p || ($p['status'] !== 'published' && (!$user || ((int)$user['id'] !== (int)$p['owner_id'] && $user['role'] !== 'admin')))) {
        fail('Không tìm thấy sản phẩm.', 404);
    }

    $q = $db->prepare('SELECT c.*, u.username FROM comments c JOIN users u ON u.id = c.user_id WHERE c.product_id = ? ORDER BY c.id DESC');
    $q->execute([$id]);
    $comments = $q->fetchAll();

    ob_start();
    ?>
    <div class="breadcrumb">
        <a href="/">Cửa hàng</a> / <a href="/?category=<?= h(urlencode($p['category'])) ?>"><?= h($p['category']) ?></a> / <?= h($p['name']) ?>
    </div>

    <section class="detail">
        <div class="detail-image">
            <img src="<?= h(product_image($p)) ?>" alt="<?= h($p['name']) ?>">
        </div>
        <div class="detail-info">
            <span class="eyebrow"><?= h(strtoupper($p['category'])) ?> · PRODUCT DETAILS</span>
            <h1><?= h($p['name']) ?><span class="title-dot">.</span></h1>
            <p class="stars">★★★★★ <small>Đánh giá cộng đồng</small></p>
            <strong class="detail-price"><?= money($p['price']) ?></strong>
            <p class="detail-desc"><?= h($p['description']) ?></p>
            <p class="muted">Đăng bởi <b><?= h($p['seller']) ?></b></p>
            <form method="post" action="/cart/add" class="buy-form">
                <?= csrf_input() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <label>
                    Số lượng
                    <input type="number" name="qty" value="1" min="1" max="10">
                </label>
                <button class="button orange">Thêm vào giỏ <span>↗</span></button>
            </form>
        </div>
    </section>

    <section class="comments">
        <div class="section-title">
            <div>
                <span class="eyebrow">CỘNG ĐỒNG</span>
                <h2>Bình luận <span class="title-dot">.</span></h2>
            </div>
            <p><?= count($comments) ?> bình luận</p>
        </div>

        <?php foreach ($comments as $c): ?>
            <article class="comment">
                <div class="avatar"><?= h(strtoupper(substr($c['username'], 0, 1))) ?></div>
                <div>
                    <strong><?= h($c['username']) ?></strong>
                    <small><?= h($c['created_at']) ?></small>
                    <p><?= nl2br(h($c['body'])) ?></p>
                </div>
            </article>
        <?php endforeach; ?>

        <?php if ($user): ?>
            <form method="post" action="/comment/add" class="comment-form">
                <?= csrf_input() ?>
                <input type="hidden" name="product_id" value="<?= $id ?>">
                <label>
                    Để lại bình luận
                    <textarea name="body" rows="4" maxlength="1000" required placeholder="Chia sẻ cảm nhận của bạn..."></textarea>
                </label>
                <button class="button dark">Đăng bình luận</button>
            </form>
        <?php else: ?>
            <p><a href="/login">Đăng nhập</a> để bình luận.</p>
        <?php endif; ?>
    </section>
    <?php
    page($p['name'], ob_get_clean(), $user);
}

function comment_add(PDO $db): void
{
    $user = require_user($db);
    if (!method_post()) {
        fail('Phương thức không hợp lệ.', 405);
    }
    check_csrf();

    $id = (int)($_POST['product_id'] ?? 0);
    $body = input('body', 1000);

    if (!$id || !$body || !get_product($db, $id)) {
        fail('Bình luận không hợp lệ.');
    }

    $q = $db->prepare('INSERT INTO comments (product_id, user_id, body) VALUES (?, ?, ?)');
    $q->execute([$id, $user['id'], $body]);

    flash('Đã đăng bình luận.');
    go('/product?id=' . $id);
}

function sell(PDO $db): void
{
    $user = require_user($db);

    if (method_post()) {
        check_csrf();
        $name = input('name', 100);
        $cat = input('category', 40);
        $desc = input('description', 800);
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT);

        if (!$name || !$desc || !in_array($cat, ['Công nghệ', 'Đời sống', 'Góc làm việc'], true) || $price === false || $price < 1 || $price > 10000) {
            fail('Thông tin sản phẩm không hợp lệ.');
        }

        $image = 'lamp.svg';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['image']['size'] > 2 * 1024 * 1024) {
                fail('Tệp vượt quá 2 MB.');
            }
            $image = 'uploads/' . bin2hex(random_bytes(8)) . '-' . basename((string)$_FILES['image']['name']);
            if (!move_uploaded_file($_FILES['image']['tmp_name'], __DIR__ . '/' . $image)) {
                fail('Không lưu được tệp.');
            }
        } elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            fail('Tải tệp thất bại.');
        }

        $q = $db->prepare('INSERT INTO products (owner_id, name, category, description, price, image) VALUES (?, ?, ?, ?, ?, ?)');
        $q->execute([$user['id'], $name, $cat, $desc, $price, $image]);

        flash('Đã đăng sản phẩm mới.');
        go('/product?id=' . $db->lastInsertId());
    }

    ob_start();
    ?>
    <section class="page-intro compact">
        <span class="eyebrow">SELL ONLINE</span>
        <h1>Đăng sản phẩm<span class="title-dot">.</span></h1>
        <p>Chia sẻ một món đồ với cộng đồng. Ảnh tải lên tối đa 2 MB.</p>
    </section>

    <div class="form-surface">
        <form method="post" enctype="multipart/form-data" class="form-stack" action="/sell">
            <?= csrf_input() ?>
            <div class="form-row">
                <label>
                    Tên sản phẩm
                    <input name="name" maxlength="100" required>
                </label>
                <label>
                    Danh mục
                    <select name="category">
                        <option>Công nghệ</option>
                        <option>Đời sống</option>
                        <option>Góc làm việc</option>
                    </select>
                </label>
            </div>
            <label>
                Mô tả
                <textarea name="description" rows="5" maxlength="800" required></textarea>
            </label>
            <div class="form-row">
                <label>
                    Giá (xu)
                    <input type="number" name="price" min="1" max="10000" required>
                </label>
                <label>
                    Ảnh sản phẩm
                    <input type="file" name="image">
                </label>
            </div>
            <button class="button orange">Đăng sản phẩm <span>↗</span></button>
        </form>
    </div>
    <?php
    page('Đăng sản phẩm', ob_get_clean(), $user);
}

function my_products(PDO $db): void
{
    $user = require_user($db);
    $q = $db->prepare('SELECT * FROM products WHERE owner_id = ? ORDER BY id DESC');
    $q->execute([$user['id']]);
    $products = $q->fetchAll();

    ob_start();
    ?>
    <section class="page-intro compact">
        <span class="eyebrow">MY LISTINGS</span>
        <h1>Sản phẩm của tôi<span class="title-dot">.</span></h1>
        <p>Quản lý bài đăng bán hàng.</p>
        <a class="button orange" href="/sell">Đăng sản phẩm mới</a>
    </section>

    <div class="data-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sản phẩm</th>
                    <th>Giá</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $p): ?>
                    <tr>
                        <td><a href="/product?id=<?= (int)$p['id'] ?>"><?= h($p['name']) ?></a></td>
                        <td><?= money($p['price']) ?></td>
                        <td><span class="status"><?= h($p['status']) ?></span></td>
                        <td><a href="/product/edit?id=<?= (int)$p['id'] ?>">Chỉnh sửa ↗</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$products): ?>
            <p class="empty">Bạn chưa đăng sản phẩm nào.</p>
        <?php endif; ?>
    </div>
    <?php
    page('Sản phẩm của tôi', ob_get_clean(), $user);
}

function product_edit(PDO $db): void
{
    $user = require_user($db);
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $p = get_product($db, $id);

    if (!$p || ((int)$p['owner_id'] !== (int)$user['id'] && $user['role'] !== 'admin')) {
        fail('Không có quyền sửa sản phẩm.', 403);
    }

    if (method_post()) {
        check_csrf();
        $name = input('name', 100);
        $desc = input('description', 800);
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_INT);

        if (!$name || !$desc || $price === false || $price < 1 || $price > 10000) {
            fail('Thông tin sản phẩm không hợp lệ.');
        }

        $q = $db->prepare('UPDATE products SET name = ?, description = ?, price = ? WHERE id = ?');
        $q->execute([$name, $desc, $price, $id]);

        flash('Đã cập nhật sản phẩm.');
        go($user['role'] === 'admin' ? '/admin?tab=products' : '/my-products');
    }

    ob_start();
    ?>
    <section class="page-intro compact">
        <span class="eyebrow">EDIT LISTING</span>
        <h1>Chỉnh sửa sản phẩm<span class="title-dot">.</span></h1>
    </section>

    <div class="form-surface">
        <form method="post" class="form-stack">
            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <label>
                Tên sản phẩm
                <input name="name" value="<?= h($p['name']) ?>" required>
            </label>
            <label>
                Mô tả
                <textarea name="description" rows="5" required><?= h($p['description']) ?></textarea>
            </label>
            <label>
                Giá (xu)
                <input type="number" name="price" min="1" max="10000" value="<?= (int)$p['price'] ?>" required>
            </label>
            <button class="button orange">Lưu thay đổi</button>
        </form>
    </div>
    <?php
    page('Sửa sản phẩm', ob_get_clean(), $user);
}

function cart_add(PDO $db): void
{
    require_user($db);
    if (!method_post()) {
        fail('Phương thức không hợp lệ.', 405);
    }
    check_csrf();

    $id = (int)($_POST['id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 1);
    $p = get_product($db, $id);

    if (!$p || $p['status'] !== 'published' || $qty < 1 || $qty > 10) {
        fail('Sản phẩm hoặc số lượng không hợp lệ.');
    }

    $_SESSION['cart'][$id] = min(10, (int)($_SESSION['cart'][$id] ?? 0) + $qty);
    flash('Đã thêm sản phẩm vào giỏ.');
    go('/cart');
}

function cart_remove(): void
{
    if (!method_post()) {
        fail('Phương thức không hợp lệ.', 405);
    }
    check_csrf();

    unset($_SESSION['cart'][(int)($_POST['id'] ?? 0)]);
    flash('Đã xóa sản phẩm khỏi giỏ.');
    go('/cart');
}

function cart(PDO $db, ?array $user): void
{
    if (!$user) {
        go('/login');
    }

    $items = [];
    $total = 0;
    foreach (($_SESSION['cart'] ?? []) as $id => $qty) {
        $p = get_product($db, (int)$id);
        if (!$p || $p['status'] !== 'published') {
            continue;
        }
        $p['qty'] = (int)$qty;
        $items[] = $p;
        $total += (int)$p['price'] * (int)$qty;
    }

    ob_start();
    ?>
    <section class="page-intro compact">
        <span class="eyebrow">YOUR CART</span>
        <h1>Giỏ hàng<span class="title-dot">.</span></h1>
        <p>Thanh toán bằng xu.</p>
    </section>

    <div class="cart-layout">
        <div class="surface">
            <?php foreach ($items as $p): ?>
                <div class="cart-item">
                    <img src="<?= h(product_image($p)) ?>" alt="">
                    <div>
                        <h3><?= h($p['name']) ?></h3>
                        <small>SL: <?= $p['qty'] ?> · <?= money($p['price']) ?> / món</small>
                    </div>
                    <strong><?= money((int)$p['price'] * $p['qty']) ?></strong>
                    <form method="post" action="/cart/remove">
                        <?= csrf_input() ?>
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="link-button">Xóa</button>
                    </form>
                </div>
            <?php endforeach; ?>
            <?php if (!$items): ?>
                <p class="empty">Giỏ hàng đang trống. <a href="/">Xem sản phẩm</a></p>
            <?php endif; ?>
        </div>

        <aside class="surface order-summary">
            <span class="eyebrow">TÓM TẮT</span>
            <h2>Đơn hàng</h2>
            <div class="summary-line">
                <span>Tạm tính</span>
                <b><?= money($total) ?></b>
            </div>
            <div class="summary-line">
                <span>Số dư ví</span>
                <b><?= money($user['balance']) ?></b>
            </div>
            <div class="summary-line total">
                <span>Thành tiền</span>
                <b><?= money($total) ?></b>
            </div>
            <?php if ($items): ?>
                <form method="post" action="/checkout" class="form-stack">
                    <?= csrf_input() ?>
                    <label>
                        Người nhận
                        <input name="shipping_name" maxlength="100" required>
                    </label>
                    <label>
                        Địa chỉ giao hàng
                        <input name="shipping_address" maxlength="250" required>
                    </label>
                    <button class="button orange">Đặt hàng <span>→</span></button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
    <?php
    page('Giỏ hàng', ob_get_clean(), $user);
}

function checkout(PDO $db): void
{
    $user = require_user($db);
    if (!method_post()) {
        fail('Phương thức không hợp lệ.', 405);
    }
    check_csrf();

    $name = input('shipping_name', 100);
    $address = input('shipping_address', 250);
    $cart = $_SESSION['cart'] ?? [];

    if (!$name || !$address || !$cart) {
        fail('Đơn hàng chưa đủ thông tin.');
    }

    $db->beginTransaction();
    try {
        $items = [];
        $total = 0;
        foreach ($cart as $id => $qty) {
            $p = get_product($db, (int)$id);
            if (!$p || $p['status'] !== 'published' || (int)$qty < 1 || (int)$qty > 10) {
                $db->rollBack();
                fail('Giỏ hàng có sản phẩm không hợp lệ.');
            }
            $items[] = [$p, (int)$qty];
            $total += (int)$p['price'] * (int)$qty;
        }

        $q = $db->prepare('SELECT balance FROM users WHERE id = ?');
        $q->execute([$user['id']]);
        if ((int)$q->fetchColumn() < $total) {
            $db->rollBack();
            fail('Số dư xu không đủ để đặt hàng.');
        }

        $q = $db->prepare('UPDATE users SET balance = balance - ? WHERE id = ?');
        $q->execute([$total, $user['id']]);

        $q = $db->prepare('INSERT INTO orders (user_id, shipping_name, shipping_address, total) VALUES (?, ?, ?, ?)');
        $q->execute([$user['id'], $name, $address, $total]);
        $orderId = (int)$db->lastInsertId();

        $q = $db->prepare('INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)');
        foreach ($items as [$p, $qty]) {
            $q->execute([$orderId, $p['id'], $qty, $p['price']]);
        }

        $db->commit();
        $_SESSION['cart'] = [];
        flash('Đặt hàng thành công. Mã đơn #' . $orderId);
        go('/orders/show?id=' . $orderId);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function orders(PDO $db): void
{
    $user = require_user($db);
    $q = $db->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC');
    $q->execute([$user['id']]);
    $orders = $q->fetchAll();

    ob_start();
    ?>
    <section class="page-intro compact">
        <span class="eyebrow">ORDER HISTORY</span>
        <h1>Đơn hàng của tôi<span class="title-dot">.</span></h1>
        <p>Theo dõi các đơn mua bằng xu.</p>
    </section>

    <div class="data-table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Mã đơn</th>
                    <th>Ngày đặt</th>
                    <th>Tổng</th>
                    <th>Trạng thái</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><b>#<?= (int)$order['id'] ?></b></td>
                        <td><?= h($order['created_at']) ?></td>
                        <td><?= money($order['total']) ?></td>
                        <td><span class="status"><?= h($order['status']) ?></span></td>
                        <td><a href="/orders/show?id=<?= (int)$order['id'] ?>">Xem chi tiết ↗</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$orders): ?>
            <p class="empty">Bạn chưa có đơn hàng nào.</p>
        <?php endif; ?>
    </div>
    <?php
    page('Đơn hàng của tôi', ob_get_clean(), $user);
}

function order_show(PDO $db): void
{
    $user = require_user($db);
    $id = (int)($_GET['id'] ?? 0);
    $q = $db->prepare('SELECT o.*, u.username FROM orders o JOIN users u ON u.id = o.user_id WHERE o.id = ?');
    $q->execute([$id]);
    $order = $q->fetch();

    if (!$order) {
        fail('Không tìm thấy đơn hàng.', 404);
    }

    $q = $db->prepare('SELECT oi.*, p.name FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?');
    $q->execute([$id]);
    $items = $q->fetchAll();

    ob_start();
    ?>
    <div class="breadcrumb">
        <a href="/orders">Đơn hàng</a> / #<?= (int)$order['id'] ?>
    </div>

    <section class="page-intro compact">
        <span class="eyebrow">ORDER DETAILS</span>
        <h1>Đơn hàng #<?= (int)$order['id'] ?><span class="title-dot">.</span></h1>
        <p>Đặt bởi <?= h($order['username']) ?> · <?= h($order['created_at']) ?></p>
    </section>

    <div class="dashboard-grid">
        <section class="surface">
            <span class="eyebrow">SẢN PHẨM</span>
            <?php foreach ($items as $item): ?>
                <div class="summary-line">
                    <span><?= h($item['name']) ?> × <?= (int)$item['quantity'] ?></span>
                    <b><?= money((int)$item['unit_price'] * (int)$item['quantity']) ?></b>
                </div>
            <?php endforeach; ?>
            <div class="summary-line total">
                <span>Tổng cộng</span>
                <b><?= money($order['total']) ?></b>
            </div>
        </section>

        <section class="surface">
            <span class="eyebrow">GIAO HÀNG</span>
            <h2><?= h($order['shipping_name']) ?></h2>
            <p><?= h($order['shipping_address']) ?></p>
            <p>Trạng thái: <span class="status"><?= h($order['status']) ?></span></p>
        </section>
    </div>
    <?php
    page('Đơn hàng #' . $order['id'], ob_get_clean(), $user);
}

function admin(PDO $db): void
{
    $user = require_admin($db);
    $tab = (string)($_GET['tab'] ?? 'overview');

    $counts = [];
    foreach (['users', 'products', 'orders', 'comments'] as $table) {
        $counts[$table] = (int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    ob_start();
    ?>
    <section class="page-intro compact">
        <span class="eyebrow">ADMIN PANEL</span>
        <h1>Trang quản trị<span class="title-dot">.</span></h1>
        <p>Quản lý thành viên, sản phẩm, đơn hàng và bình luận trên cửa hàng.</p>
    </section>

    <div class="admin-stats">
        <div>
            <span>THÀNH VIÊN</span>
            <strong><?= $counts['users'] ?></strong>
        </div>
        <div>
            <span>SẢN PHẨM</span>
            <strong><?= $counts['products'] ?></strong>
        </div>
        <div>
            <span>ĐƠN HÀNG</span>
            <strong><?= $counts['orders'] ?></strong>
        </div>
        <div>
            <span>BÌNH LUẬN</span>
            <strong><?= $counts['comments'] ?></strong>
        </div>
    </div>

    <div class="admin-tabs">
        <a class="<?= $tab === 'overview' ? 'active' : '' ?>" href="/admin">Tổng quan</a>
        <a class="<?= $tab === 'users' ? 'active' : '' ?>" href="/admin?tab=users">Thành viên</a>
        <a class="<?= $tab === 'products' ? 'active' : '' ?>" href="/admin?tab=products">Sản phẩm</a>
        <a class="<?= $tab === 'orders' ? 'active' : '' ?>" href="/admin?tab=orders">Đơn hàng</a>
        <a class="<?= $tab === 'comments' ? 'active' : '' ?>" href="/admin?tab=comments">Bình luận</a>
    </div>

    <?php if ($tab === 'overview'): ?>
        <?php $recent = $db->query('SELECT o.*, u.username FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC LIMIT 5')->fetchAll(); ?>
        <div class="dashboard-grid">
            <section class="surface">
                <span class="eyebrow">TỔNG QUAN</span>
                <h2>Hoạt động gần đây</h2>
                <p>Các đơn mới và trạng thái xử lý.</p>
                <?php foreach ($recent as $o): ?>
                    <div class="summary-line">
                        <span><a href="/orders/show?id=<?= (int)$o['id'] ?>">#<?= (int)$o['id'] ?></a> · <?= h($o['username']) ?></span>
                        <b><?= money($o['total']) ?></b>
                    </div>
                <?php endforeach; ?>
            </section>

            <section class="surface">
                <span class="eyebrow">QUẢN LÝ</span>
                <h2>Thao tác nhanh</h2>
                <div class="quick-links column">
                    <a href="/admin?tab=products">Duyệt sản phẩm <span>↗</span></a>
                    <a href="/admin?tab=orders">Xử lý đơn hàng <span>↗</span></a>
                    <a href="/admin?tab=users">Phân quyền thành viên <span>↗</span></a>
                    <a href="/admin?tab=comments">Kiểm duyệt bình luận <span>↗</span></a>
                </div>
            </section>
        </div>

    <?php elseif ($tab === 'users'): ?>
        <?php $rows = $db->query('SELECT id, username, email, role, balance FROM users ORDER BY id')->fetchAll(); ?>
        <div class="data-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tài khoản</th>
                        <th>Email</th>
                        <th>Vai trò</th>
                        <th>Ví</th>
                        <th>Đổi quyền</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><b><?= h($r['username']) ?></b></td>
                            <td><?= h($r['email']) ?></td>
                            <td><span class="status"><?= h($r['role']) ?></span></td>
                            <td><?= money($r['balance']) ?></td>
                            <td>
                                <form method="post" action="/admin/action" class="inline-form">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <select name="value">
                                        <option value="user" <?= $r['role'] === 'user' ? 'selected' : '' ?>>user</option>
                                        <option value="admin" <?= $r['role'] === 'admin' ? 'selected' : '' ?>>admin</option>
                                    </select>
                                    <button>Lưu</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'products'): ?>
        <?php $rows = $db->query('SELECT p.*, u.username FROM products p JOIN users u ON u.id = p.owner_id ORDER BY p.id DESC')->fetchAll(); ?>
        <div class="data-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Sản phẩm</th>
                        <th>Người đăng</th>
                        <th>Giá</th>
                        <th>Trạng thái</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><a href="/product?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a></td>
                            <td><?= h($r['username']) ?></td>
                            <td><?= money($r['price']) ?></td>
                            <td><span class="status"><?= h($r['status']) ?></span></td>
                            <td>
                                <a href="/product/edit?id=<?= (int)$r['id'] ?>">Sửa</a>
                                <form method="post" action="/admin/action" class="inline-form">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="product">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <input type="hidden" name="value" value="<?= $r['status'] === 'published' ? 'hidden' : 'published' ?>">
                                    <button><?= $r['status'] === 'published' ? 'Ẩn' : 'Hiện' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'orders'): ?>
        <?php $rows = $db->query('SELECT o.*, u.username FROM orders o JOIN users u ON u.id = o.user_id ORDER BY o.id DESC')->fetchAll(); ?>
        <div class="data-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Mã đơn</th>
                        <th>Khách</th>
                        <th>Giao tới</th>
                        <th>Tổng</th>
                        <th>Trạng thái</th>
                        <th>Cập nhật</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><a href="/orders/show?id=<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></a></td>
                            <td><?= h($r['username']) ?></td>
                            <td><?= h($r['shipping_name']) ?><small><?= h($r['shipping_address']) ?></small></td>
                            <td><?= money($r['total']) ?></td>
                            <td><span class="status"><?= h($r['status']) ?></span></td>
                            <td>
                                <form method="post" action="/admin/action" class="inline-form">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="order">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <select name="value">
                                        <?php foreach (['processing', 'shipped', 'completed', 'cancelled'] as $status): ?>
                                            <option value="<?= $status ?>" <?= $r['status'] === $status ? 'selected' : '' ?>><?= $status ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button>Lưu</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($tab === 'comments'): ?>
        <?php
        $rows = $db->query(
            'SELECT c.*, u.username, p.name AS product_name '
            . 'FROM comments c '
            . 'JOIN users u ON u.id = c.user_id '
            . 'JOIN products p ON p.id = c.product_id '
            . 'ORDER BY c.id DESC'
        )->fetchAll();
        ?>
        <div class="data-table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Người viết</th>
                        <th>Sản phẩm</th>
                        <th>Nội dung</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td><?= h($r['username']) ?></td>
                            <td><a href="/product?id=<?= (int)$r['product_id'] ?>"><?= h($r['product_name']) ?></a></td>
                            <td><?= h($r['body']) ?></td>
                            <td>
                                <form method="post" action="/admin/action">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete_comment">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="link-button" onclick="return confirm('Xóa bình luận này?')">Xóa</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <div class="empty">Không tìm thấy mục quản trị.</div>
    <?php endif; ?>

    <?php
    page('Trang quản trị', ob_get_clean(), $user);
}

function admin_action(PDO $db): void
{
    $user = require_admin($db);
    if (!method_post()) {
        fail('Phương thức không hợp lệ.', 405);
    }
    check_csrf();

    $action = input('action');
    $id = (int)($_POST['id'] ?? 0);
    $value = input('value');

    if (!$id) {
        fail('Thiếu ID.');
    }

    switch ($action) {
        case 'role':
            if (!in_array($value, ['user', 'admin'], true)) {
                fail('Vai trò không hợp lệ.');
            }
            if ($id === (int)$user['id'] && $value !== 'admin') {
                fail('Không thể tự hạ quyền tài khoản đang dùng.');
            }
            $q = $db->prepare('UPDATE users SET role = ? WHERE id = ?');
            $q->execute([$value, $id]);
            $tab = 'users';
            break;

        case 'product':
            if (!in_array($value, ['published', 'hidden'], true)) {
                fail('Trạng thái không hợp lệ.');
            }
            $q = $db->prepare('UPDATE products SET status = ? WHERE id = ?');
            $q->execute([$value, $id]);
            $tab = 'products';
            break;

        case 'order':
            if (!in_array($value, ['processing', 'shipped', 'completed', 'cancelled'], true)) {
                fail('Trạng thái không hợp lệ.');
            }
            $q = $db->prepare('UPDATE orders SET status = ? WHERE id = ?');
            $q->execute([$value, $id]);
            $tab = 'orders';
            break;

        case 'delete_comment':
            $q = $db->prepare('DELETE FROM comments WHERE id = ?');
            $q->execute([$id]);
            $tab = 'comments';
            break;

        default:
            fail('Hành động không hợp lệ.');
    }

    flash('Đã cập nhật dữ liệu quản trị.');
    go('/admin?tab=' . $tab);
}

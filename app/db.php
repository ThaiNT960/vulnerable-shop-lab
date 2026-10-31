<?php
declare(strict_types=1);

function database(): PDO
{
    $path = getenv('SHOP_DB_PATH') ?: '/var/lib/shop-lab/shop.sqlite';
    $fresh = !is_file($path);
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 5000');
    if ($fresh) {
        initialize_database($db);
    }
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users(email COLLATE NOCASE)');
    return $db;
}

function initialize_database(PDO $db): void
{
    $db->beginTransaction();
    try {
        $db->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    email TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    balance INTEGER NOT NULL DEFAULT 200
);
CREATE TABLE products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    description TEXT NOT NULL,
    price INTEGER NOT NULL,
    image TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'published',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(owner_id) REFERENCES users(id)
);
CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    body TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(product_id) REFERENCES products(id),
    FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    shipping_name TEXT NOT NULL,
    shipping_address TEXT NOT NULL,
    total INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'processing',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE TABLE order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price INTEGER NOT NULL,
    FOREIGN KEY(order_id) REFERENCES orders(id),
    FOREIGN KEY(product_id) REFERENCES products(id)
);
CREATE TABLE transfers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sender_id INTEGER NOT NULL,
    recipient_id INTEGER NOT NULL,
    amount INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE lab_secrets (id INTEGER PRIMARY KEY, secret TEXT NOT NULL);
SQL);
        $users = [
            ['user1', 'user123', 'user1@example.test', 'user', 240],
            ['user2', 'user2123', 'user2@example.test', 'user', 320],
            ['admin', 'admin123', 'admin@example.test', 'admin', 500],
        ];
        $insertUser = $db->prepare('INSERT INTO users(username,password_hash,email,role,balance) VALUES(?,?,?,?,?)');
        foreach ($users as [$name, $password, $email, $role, $balance]) {
            $insertUser->execute([$name, password_hash($password, PASSWORD_DEFAULT), $email, $role, $balance]);
        }
        $products = [
            [2, 'Đèn bàn Halo', 'Góc làm việc', 'Ánh sáng ấm cho những buổi đọc sách và làm việc muộn.', 42, 'lamp.svg'],
            [2, 'Bình giữ nhiệt Terra', 'Đời sống', 'Chiếc bình nhỏ gọn đồng hành mỗi ngày.', 28, 'bottle.svg'],
            [3, 'Tai nghe Studio', 'Công nghệ', 'Tập trung vào âm thanh và giữ mọi thứ thật đơn giản.', 89, 'headphones.svg'],
            [2, 'Máy ảnh Mini', 'Công nghệ', 'Ghi lại những khoảnh khắc thường ngày.', 74, 'camera.svg'],
            [3, 'Túi canvas Daylight', 'Đời sống', 'Không gian vừa đủ cho những món đồ quen thuộc.', 36, 'bag.svg'],
            [2, 'Đồng hồ Orbit', 'Góc làm việc', 'Một điểm nhấn nhẹ trên bàn làm việc.', 55, 'clock.svg'],
        ];
        $insertProduct = $db->prepare('INSERT INTO products(owner_id,name,category,description,price,image) VALUES(?,?,?,?,?,?)');
        foreach ($products as $product) {
            $insertProduct->execute($product);
        }
        $db->prepare('INSERT INTO comments(product_id,user_id,body) VALUES(?,?,?)')->execute([1, 1, 'Ánh sáng dịu, thiết kế đẹp.']);
        $db->prepare('INSERT INTO lab_secrets(id,secret) VALUES(1,?)')->execute(['LAB{sqlite_union_revealed_the_hidden_note}']);
        $db->prepare('INSERT INTO orders(id,user_id,shipping_name,shipping_address,total,status) VALUES(?,?,?,?,?,?)')
            ->execute([1001, 2, 'User 2', '18 Phố Hoa, Hà Nội', 42, 'shipped']);
        $db->prepare('INSERT INTO order_items(order_id,product_id,quantity,unit_price) VALUES(?,?,?,?)')->execute([1001, 1, 1, 42]);
        $db->prepare('INSERT INTO orders(id,user_id,shipping_name,shipping_address,total,status) VALUES(?,?,?,?,?,?)')
            ->execute([1002, 1, 'User 1', '7 Đường Mây, Đà Nẵng', 28, 'processing']);
        $db->prepare('INSERT INTO order_items(order_id,product_id,quantity,unit_price) VALUES(?,?,?,?)')->execute([1002, 2, 1, 28]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

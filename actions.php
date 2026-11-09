<?php
require __DIR__ . '/app/bootstrap.php';

$pdo = null;
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function go($url = 'index.php')
{
    header('Location: ' . $url);
    exit;
}

function makeSlug($name, $id = 0)
{
    $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $slug = strtolower($slug ?: 'san-pham');
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    if ($slug === '') $slug = 'san-pham';

    $base = $slug;
    $n = 1;

    while (true) {
        $sql = "SELECT id FROM products WHERE slug = ?";
        $params = [$slug];

        if ($id) {
            $sql .= " AND id <> ?";
            $params[] = $id;
        }

        $stmt = db()->prepare($sql . " LIMIT 1");
        $stmt->execute($params);

        if (!$stmt->fetch()) return $slug;

        $slug = $base . '-' . (++$n);
    }
}

function uploadProductImages($productId)
{
    if (empty($_FILES['images']['tmp_name'])) return;

    $folder = __DIR__ . '/uploads/products/';
    if (!is_dir($folder)) mkdir($folder, 0755, true);

    $pdo = db();

    foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
        if (!is_uploaded_file($tmp)) continue;
        if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($_FILES['images']['size'][$i] > 4 * 1024 * 1024) continue;

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $types = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($types[$mime])) continue;

        $filename = bin2hex(random_bytes(12)) . '.' . $types[$mime];

        if (move_uploaded_file($tmp, $folder . $filename)) {
            $path = 'uploads/products/' . $filename;

            $stmt = $pdo->prepare(
                "INSERT INTO product_images(product_id,path) VALUES(?,?)"
            );
            $stmt->execute([$productId, $path]);
        }
    }

    $stmt = $pdo->prepare(
        "SELECT path FROM product_images WHERE product_id=? ORDER BY id"
    );
    $stmt->execute([$productId]);
    $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($paths) {
        $stmt = $pdo->prepare(
            "UPDATE products SET image=?, images=? WHERE id=?"
        );
        $stmt->execute([
            $paths[0],
            json_encode($paths, JSON_UNESCAPED_UNICODE),
            $productId
        ]);
    }
}

function saveVariants($productId)
{
    $pdo = db();

    $colors = $_POST['variant_color'] ?? [];
    $sizes = $_POST['variant_size'] ?? [];
    $stocks = $_POST['variant_stock'] ?? [];
    $skus = $_POST['variant_sku'] ?? [];

    $stmt = $pdo->prepare(
        "SELECT id FROM product_variants WHERE product_id=?"
    );
    $stmt->execute([$productId]);
    $old = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $submitted = [];

    foreach ($colors as $i => $color) {
        $color = trim($color);
        $size = trim($sizes[$i] ?? '');
        $stock = (int)($stocks[$i] ?? 0);
        $sku = trim($skus[$i] ?? '');

        if ($color === '' || $size === '') continue;
        if ($stock < 0) throw new Exception('Tồn kho biến thể không hợp lệ.');

        $stmt = $pdo->prepare(
            "SELECT id FROM product_variants
             WHERE product_id=? AND color=? AND size=?"
        );
        $stmt->execute([$productId, $color, $size]);
        $existing = $stmt->fetchColumn();

        if ($existing) {
            $stmt = $pdo->prepare(
                "UPDATE product_variants
                 SET stock=?, sku=?, updated_at=NOW()
                 WHERE id=?"
            );
            $stmt->execute([$stock, $sku ?: null, $existing]);
            $submitted[] = (int)$existing;
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO product_variants
                 (product_id,color,size,sku,stock,created_at,updated_at)
                 VALUES(?,?,?,?,?,NOW(),NOW())"
            );
            $stmt->execute([
                $productId,
                $color,
                $size,
                $sku ?: null,
                $stock
            ]);
            $submitted[] = (int)$pdo->lastInsertId();
        }
    }

    // Chỉ xóa biến thể cũ nếu biểu mẫu thực sự gửi danh sách biến thể.
    if (isset($_POST['variant_color'])) {
        foreach ($old as $oldId) {
            if (!in_array((int)$oldId, $submitted, true)) {
                $stmt = $pdo->prepare(
                    "DELETE FROM product_variants WHERE id=? AND product_id=?"
                );
                $stmt->execute([$oldId, $productId]);
            }
        }
    }

    // Tự động tính tồn kho tổng
    $stmt = $pdo->prepare(
        "UPDATE products
         SET stock = (
             SELECT COALESCE(SUM(stock), 0)
             FROM product_variants
             WHERE product_id = ?
         )
         WHERE id = ?"
    );

    $stmt->execute([$productId, $productId]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') verify();

    switch ($action) {
        case 'login':
            $stmt = db()->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
            $stmt->execute([trim($_POST['email'] ?? '')]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($_POST['password'] ?? '', $user['password'])) {
                throw new Exception('Sai email hoặc mật khẩu.');
            }
            if ($user['role'] !== 'admin') {
                throw new Exception('Tài khoản không có quyền quản trị.');
            }

            session_regenerate_id(true);
            $_SESSION['user'] = $user;
            go('index.php?page=admin');

        case 'logout':
            $_SESSION = [];
            session_destroy();
            go();

        case 'save':
            if (!admin()) throw new Exception('Không có quyền quản trị.');

            $pdo = db();
            $pdo->beginTransaction();

            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $sku = trim($_POST['sku'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $sale = ($_POST['sale_price'] ?? '') !== ''
                ? (float)$_POST['sale_price'] : null;
            $stock = max(0, (int)($_POST['stock'] ?? 0));

            if ($name === '') throw new Exception('Nhập tên sản phẩm.');
            if ($price <= 0) throw new Exception('Giá bán phải lớn hơn 0.');

            $slug = makeSlug($name, $id);

            $colors = [];
            $sizes = [];

            $variantColors = $_POST['variant_color'] ?? [];
            $variantSizes = $_POST['variant_size'] ?? [];

            foreach ($variantColors as $i => $color) {
                $color = trim($color);
                $size = trim($variantSizes[$i] ?? '');

                if ($color !== '' && !in_array($color, $colors, true)) {
                    $colors[] = $color;
                }

                if ($size !== '' && !in_array($size, $sizes, true)) {
                    $sizes[] = $size;
                }
            }

            if ($id) {
                $stmt = $pdo->prepare(
                    "UPDATE products SET name=?,slug=?,sku=?,price=?,
                     sale_price=?,stock=?,colors=?,sizes=?,description=?
                     WHERE id=?"
                );
                $stmt->execute([
                    $name,
                    $slug,
                    $sku ?: null,
                    $price,
                    $sale,
                    $stock,
                    json_encode($colors, JSON_UNESCAPED_UNICODE),
                    json_encode($sizes, JSON_UNESCAPED_UNICODE),
                    $description,
                    $id
                ]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO products
                     (name,slug,sku,price,sale_price,stock,colors,sizes,
                      description,is_active,created_at)
                     VALUES(?,?,?,?,?,?,?,?,?,1,NOW())"
                );
                $stmt->execute([
                    $name,
                    $slug,
                    $sku ?: null,
                    $price,
                    $sale,
                    $stock,
                    json_encode($colors, JSON_UNESCAPED_UNICODE),
                    json_encode($sizes, JSON_UNESCAPED_UNICODE),
                    $description
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            uploadProductImages($id);
            saveVariants($id);

            $pdo->commit();
            flash('Đã lưu sản phẩm và biến thể thành công!');
            go('index.php?page=admin');

        case 'add':
            $id = (int)($_POST['id'] ?? 0);
            $variantId = (int)($_POST['variant_id'] ?? 0);
            $qty = max(1, (int)($_POST['qty'] ?? 1));

            if ($id <= 0) {
                throw new Exception('ID sản phẩm không hợp lệ.');
            }

            $stmt = db()->prepare(
                "SELECT p.id, p.name, p.stock, p.is_active, p.price,
                p.sale_price, p.image,
                v.id AS variant_id, v.color, v.size,
                v.stock AS variant_stock
         FROM products p
         LEFT JOIN product_variants v
            ON v.product_id = p.id AND v.id = ?
         WHERE p.id = ? AND p.is_active = 1"
            );

            $stmt->execute([$variantId, $id]);
            $p = $stmt->fetch();

            if (!$p) {
                throw new Exception('Sản phẩm không tồn tại.');
            }

            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM product_variants WHERE product_id = ?"
            );
            $stmt->execute([$id]);
            $hasVariants = (int)$stmt->fetchColumn() > 0;

            if ($hasVariants && !$p['variant_id']) {
                throw new Exception('Vui lòng chọn màu và size.');
            }

            $key = $variantId ? 'v' . $variantId : 'p' . $id;

            $available = $variantId
                ? (int)$p['variant_stock']
                : (int)$p['stock'];

            $current = (int)($_SESSION['cart'][$key]['qty'] ?? 0);

            if ($current + $qty > $available) {
                throw new Exception('Số lượng vượt quá tồn kho.');
            }

            $_SESSION['cart'][$key] = [
                'product_id' => $id,
                'variant_id' => $variantId,
                'qty' => $current + $qty,
                'name' => $p['name'],
                'color' => $p['color'] ?? '',
                'size' => $p['size'] ?? '',
                'price' => $p['sale_price'] ?: $p['price'],
                'image' => $p['image']
            ];

            go('index.php?page=cart');
        case 'update_cart':
            $key = $_POST['cart_key'] ?? '';
            $qty = (int)($_POST['qty'] ?? 0);

            if ($key === '' || !isset($_SESSION['cart'][$key])) {
                throw new Exception('Sản phẩm trong giỏ hàng không tồn tại.');
            }

            if ($qty < 1) {
                throw new Exception('Số lượng phải lớn hơn hoặc bằng 1.');
            }

            $item = $_SESSION['cart'][$key];
            $productId = (int)$item['product_id'];
            $variantId = (int)($item['variant_id'] ?? 0);

            if ($variantId > 0) {
                // Kiểm tra tồn kho biến thể
                $stmt = db()->prepare(
                    "SELECT v.stock, p.is_active
                     FROM product_variants v
                     JOIN products p ON p.id = v.product_id
                     WHERE v.id = ? AND v.product_id = ?
                     LIMIT 1"
                );
                $stmt->execute([$variantId, $productId]);
            } else {
                // Kiểm tra tồn kho sản phẩm không có biến thể
                $stmt = db()->prepare(
                    "SELECT stock, is_active
                     FROM products
                     WHERE id = ?
                     LIMIT 1"
                );
                $stmt->execute([$productId]);
            }

            $productStock = $stmt->fetch();

            if (!$productStock || (int)$productStock['is_active'] !== 1) {
                throw new Exception('Sản phẩm không còn khả dụng.');
            }

            if ($qty > (int)$productStock['stock']) {
                throw new Exception(
                    'Số lượng vượt quá tồn kho. Hiện chỉ còn ' .
                        (int)$productStock['stock'] . ' sản phẩm.'
                );
            }

            $_SESSION['cart'][$key]['qty'] = $qty;

            flash('Đã cập nhật số lượng thành công.');
            go('index.php?page=cart');
        case 'remove_cart':

            $key = $_POST['cart_key'] ?? '';

            if ($key === '' || !isset($_SESSION['cart'][$key])) {
                throw new Exception('Sản phẩm trong giỏ hàng không tồn tại.');
            }

            unset($_SESSION['cart'][$key]);

            go('index.php?page=cart');
        case 'clear_cart':

            $_SESSION['cart'] = [];

            go('index.php?page=cart');
        case 'delete_stock_import':

            if (!admin()) {
                throw new Exception('Bạn không có quyền quản trị.');
            }

            $importId = (int)($_POST['import_id'] ?? 0);

            if ($importId <= 0) {
                throw new Exception('Mã lịch sử nhập kho không hợp lệ.');
            }

            $stmt = db()->prepare(
                "DELETE FROM stock_imports WHERE id = ?"
            );

            $stmt->execute([$importId]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Không tìm thấy lịch sử nhập kho.');
            }

            flash('Đã xóa lịch sử nhập kho thành công.');

            go('index.php?page=stock_history');

            break;
        case 'import_stock':

            if (!admin()) {
                throw new Exception('Bạn không có quyền quản trị.');
            }

            $variantId = (int)($_POST['variant_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);
            $note = trim($_POST['note'] ?? '');

            if ($variantId <= 0) {
                throw new Exception('Vui lòng chọn biến thể sản phẩm.');
            }

            if ($quantity <= 0) {
                throw new Exception('Số lượng nhập phải lớn hơn 0.');
            }

            $pdo = db();
            $pdo->beginTransaction();

            // Kiểm tra biến thể có tồn tại không
            $stmt = $pdo->prepare(
                "SELECT id, product_id, stock
                 FROM product_variants
                 WHERE id = ?
                 FOR UPDATE"
            );

            $stmt->execute([$variantId]);
            $variant = $stmt->fetch();

            if (!$variant) {
                throw new Exception('Không tìm thấy biến thể sản phẩm.');
            }

            $productId = (int)$variant['product_id'];

            // Cộng tồn kho cho biến thể
            $stmt = $pdo->prepare(
                "UPDATE product_variants
                 SET stock = stock + ?, updated_at = NOW()
                 WHERE id = ?"
            );

            $stmt->execute([$quantity, $variantId]);

            // Tính lại tồn kho tổng sản phẩm
            $stmt = $pdo->prepare(
                "UPDATE products
                 SET stock = (
                     SELECT COALESCE(SUM(stock), 0)
                     FROM product_variants
                     WHERE product_id = ?
                 )
                 WHERE id = ?"
            );

            $stmt->execute([$productId, $productId]);

            // Lưu lịch sử nhập kho
            $stmt = $pdo->prepare(
                "INSERT INTO stock_imports
                 (product_id, variant_id, quantity, note)
                 VALUES (?, ?, ?, ?)"
            );

            $stmt->execute([
                $productId,
                $variantId,
                $quantity,
                $note !== '' ? $note : null
            ]);
            // Ghi nhận lịch sử thay đổi tồn kho
            $stmt = $pdo->prepare(
                "INSERT INTO stock_movements
     (product_id, variant_id, movement_type, quantity_change, note)
     VALUES (?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $productId,
                $variantId,
                'import',
                $quantity,
                $note !== '' ? $note : 'Nhập kho sản phẩm'
            ]);
            $pdo->commit();

            flash('Nhập kho thành công! Đã cập nhật tồn kho sản phẩm.');

            go('index.php?page=stock_import');
        case 'adjust_stock':

            if (!admin()) {
                throw new Exception('Bạn không có quyền quản trị.');
            }

            $variantId = (int)($_POST['variant_id'] ?? 0);
            $quantity = (int)($_POST['quantity'] ?? 0);
            $adjustmentType = $_POST['adjustment_type'] ?? '';
            $note = trim($_POST['note'] ?? '');

            if ($variantId <= 0) {
                throw new Exception('Vui lòng chọn biến thể sản phẩm.');
            }

            if ($quantity <= 0) {
                throw new Exception('Số lượng điều chỉnh phải lớn hơn 0.');
            }

            if (!in_array($adjustmentType, ['increase', 'decrease'], true)) {
                throw new Exception('Loại điều chỉnh không hợp lệ.');
            }

            if ($note === '') {
                throw new Exception('Vui lòng nhập lý do điều chỉnh.');
            }

            $pdo = db();
            $pdo->beginTransaction();

            try {

                // Lấy thông tin biến thể và khóa dữ liệu
                $stmt = $pdo->prepare(
                    "SELECT id, product_id, stock
                     FROM product_variants
                     WHERE id = ?
                     FOR UPDATE"
                );

                $stmt->execute([$variantId]);
                $variant = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$variant) {
                    throw new Exception('Không tìm thấy biến thể sản phẩm.');
                }

                $productId = (int)$variant['product_id'];
                $oldStock = (int)$variant['stock'];

                // Tính số lượng thay đổi
                $quantityChange = $adjustmentType === 'increase'
                    ? $quantity
                    : -$quantity;

                $newStock = $oldStock + $quantityChange;

                if ($newStock < 0) {
                    throw new Exception(
                        'Không thể giảm quá số lượng tồn kho hiện tại. Tồn kho hiện tại: ' . $oldStock
                    );
                }

                // Cập nhật tồn kho biến thể
                $stmt = $pdo->prepare(
                    "UPDATE product_variants
                     SET stock = ?, updated_at = NOW()
                     WHERE id = ?"
                );

                $stmt->execute([$newStock, $variantId]);

                // Cập nhật tổng tồn kho sản phẩm
                $stmt = $pdo->prepare(
                    "UPDATE products
                     SET stock = (
                         SELECT COALESCE(SUM(stock), 0)
                         FROM product_variants
                         WHERE product_id = ?
                     )
                     WHERE id = ?"
                );

                $stmt->execute([$productId, $productId]);

                // Tạo mã phiếu điều chỉnh
                $referenceCode = 'DC' . strtoupper(bin2hex(random_bytes(4)));

                // Ghi lịch sử điều chỉnh tồn kho
                $stmt = $pdo->prepare(
                    "INSERT INTO stock_movements
                     (
                         product_id,
                         variant_id,
                         movement_type,
                         quantity_change,
                         reference_code,
                         note
                     )
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $productId,
                    $variantId,
                    'adjustment',
                    $quantityChange,
                    $referenceCode,
                    $note
                ]);

                $pdo->commit();

                flash(
                    'Điều chỉnh tồn kho thành công! Mã phiếu: ' . $referenceCode
                );

                go('index.php?page=stock_adjustment');
            } catch (Throwable $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        case 'toggle_product':
            if (!admin()) {
                throw new Exception('Bạn không có quyền quản trị.');
            }

            $id = (int)($_POST['id'] ?? 0);
            $newStatus = (int)($_POST['new_status'] ?? -1);

            if ($id <= 0 || !in_array($newStatus, [0, 1], true)) {
                throw new Exception('Thông tin sản phẩm không hợp lệ.');
            }

            $pdo = db();

            $stmt = $pdo->prepare(
                "UPDATE products SET is_active = ? WHERE id = ?"
            );
            $stmt->execute([$newStatus, $id]);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare(
                    "SELECT id, is_active FROM products WHERE id = ?"
                );
                $check->execute([$id]);
                $product = $check->fetch();

                if (!$product) {
                    throw new Exception('Không tìm thấy sản phẩm.');
                }

                if ((int)$product['is_active'] !== $newStatus) {
                    throw new Exception('Không cập nhật được trạng thái sản phẩm.');
                }
            }

            flash(
                $newStatus === 1
                    ? 'Đã hiện sản phẩm thành công.'
                    : 'Đã ẩn sản phẩm thành công.'
            );

            go('index.php?page=admin');
        case 'delete_product_image':
            if (!admin()) {
                throw new Exception('Bạn không có quyền quản trị.');
            }

            $productId = (int)($_GET['product_id'] ?? 0);
            $imageId = (int)($_GET['image_id'] ?? 0);

            if ($productId <= 0 || $imageId <= 0) {
                throw new Exception('Thông tin ảnh không hợp lệ.');
            }

            $pdo = db();
            $pdo->beginTransaction();

            // Lấy đúng ảnh thuộc sản phẩm
            $stmt = $pdo->prepare(
                "SELECT id, path
                 FROM product_images
                 WHERE id = ? AND product_id = ?
                 LIMIT 1"
            );
            $stmt->execute([$imageId, $productId]);
            $image = $stmt->fetch();

            if (!$image) {
                throw new Exception('Không tìm thấy ảnh cần xóa.');
            }

            // Xóa ảnh khỏi danh sách của sản phẩm
            $stmt = $pdo->prepare(
                "DELETE FROM product_images
                 WHERE id = ? AND product_id = ?"
            );
            $stmt->execute([$imageId, $productId]);

            // Lấy danh sách ảnh còn lại
            $stmt = $pdo->prepare(
                "SELECT path
                 FROM product_images
                 WHERE product_id = ?
                 ORDER BY id ASC"
            );
            $stmt->execute([$productId]);
            $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Cập nhật ảnh đại diện và danh sách ảnh
            $stmt = $pdo->prepare(
                "UPDATE products
                 SET image = ?, images = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $paths[0] ?? null,
                json_encode($paths, JSON_UNESCAPED_UNICODE),
                $productId
            ]);

            $pdo->commit();

            // Chỉ xóa file nằm trong thư mục uploads/products
            $relativePath = ltrim($image['path'], '/\\');
            $prefix = 'uploads/products/';

            if (strpos($relativePath, $prefix) === 0) {
                $filename = substr($relativePath, strlen($prefix));

                // Chỉ chấp nhận tên file đơn giản, không có đường dẫn con
                if (
                    $filename !== '' &&
                    basename($filename) === $filename
                ) {
                    $file = __DIR__ . '/' . $prefix . $filename;

                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }

            flash('Đã xóa ảnh sản phẩm thành công.');
            go('index.php?page=edit_product&id=' . $productId);

        case 'delete':
            if (!admin()) {
                throw new Exception('Bạn không có quyền quản trị.');
            }

            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('ID sản phẩm không hợp lệ.');
            }

            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM order_items WHERE product_id=?"
            );
            $stmt->execute([$id]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new Exception(
                    'Sản phẩm đã có trong đơn hàng. Hãy ẩn sản phẩm thay vì xóa để giữ lịch sử.'
                );
            }

            $stmt = db()->prepare(
                "SELECT path FROM product_images WHERE product_id=?"
            );
            $stmt->execute([$id]);
            $paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $pdo = db();
            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM product_variants WHERE product_id=?")
                ->execute([$id]);

            $pdo->prepare("DELETE FROM product_images WHERE product_id=?")
                ->execute([$id]);

            $pdo->prepare("DELETE FROM products WHERE id=?")
                ->execute([$id]);

            $pdo->commit();

            foreach ($paths as $path) {
                $file = __DIR__ . '/' . ltrim($path, '/');
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            flash('Đã xóa sản phẩm thành công.');
            go('index.php?page=admin');


        case 'order_status':
            if (!admin()) {
                throw new Exception('Bạn không có quyền quản trị.');
            }

            $orderId = (int)($_POST['order_id'] ?? 0);
            $status = $_POST['status'] ?? '';

            $allowed = [
                'pending',
                'confirmed',
                'shipping',
                'completed',
                'cancelled',
                'failed_delivery',
                'returned'
            ];

            if ($orderId <= 0 || !in_array($status, $allowed, true)) {
                throw new Exception('Thông tin cập nhật đơn hàng không hợp lệ.');
            }

            $pdo = db();
            $pdo->beginTransaction();

            // Khóa đơn hàng để tránh cập nhật hoặc hoàn kho nhiều lần
            $stmt = $pdo->prepare(
                "SELECT status, code FROM orders WHERE id = ? FOR UPDATE"
            );
            $stmt->execute([$orderId]);
            $orderData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$orderData) {
                throw new Exception('Không tìm thấy đơn hàng.');
            }

            $oldStatus = $orderData['status'];
            $orderCode = $orderData['code'];

            // Quy định chuyển trạng thái
            $transitions = [
                'pending' => ['confirmed', 'cancelled'],
                'confirmed' => ['shipping', 'cancelled'],
                'shipping' => ['completed', 'failed_delivery'],
                'failed_delivery' => ['returned'],
                'completed' => [],
                'cancelled' => [],
                'returned' => []
            ];

            if (
                !isset($transitions[$oldStatus]) ||
                !in_array($status, $transitions[$oldStatus], true)
            ) {
                throw new Exception(
                    'Không thể chuyển từ trạng thái hiện tại sang trạng thái mới.'
                );
            }

            // Chỉ hoàn kho khi hủy đơn hoặc hàng đã thực sự hoàn về
            if (in_array($status, ['cancelled', 'returned'], true)) {

                $stmt = $pdo->prepare(
                    "SELECT product_id, quantity, color, size
                     FROM order_items
                     WHERE order_id = ?"
                );
                $stmt->execute([$orderId]);
                $orderItems = $stmt->fetchAll();

                foreach ($orderItems as $item) {
                    $productId = (int)$item['product_id'];
                    $quantity = (int)$item['quantity'];
                    $color = trim($item['color'] ?? '');
                    $size = trim($item['size'] ?? '');
                    $variantId = null;

                    if ($productId <= 0 || $quantity <= 0) {
                        throw new Exception(
                            'Chi tiết sản phẩm trong đơn hàng không hợp lệ.'
                        );
                    }

                    // Sản phẩm có màu và size: hoàn kho biến thể
                    if ($color !== '' && $size !== '') {

                        $stmt = $pdo->prepare(
                            "SELECT id
                             FROM product_variants
                             WHERE product_id = ?
                               AND color = ?
                               AND size = ?
                             FOR UPDATE"
                        );
                        $stmt->execute([$productId, $color, $size]);
                        $variantId = $stmt->fetchColumn();

                        if (!$variantId) {
                            throw new Exception(
                                'Không tìm thấy biến thể ' . $color . ' - ' . $size .
                                    ' để hoàn kho. Hãy kiểm tra lại sản phẩm.'
                            );
                        }

                        $stmt = $pdo->prepare(
                            "UPDATE product_variants
                             SET stock = stock + ?, updated_at = NOW()
                             WHERE id = ?"
                        );
                        $stmt->execute([$quantity, $variantId]);

                        // Cập nhật lại tồn kho tổng sản phẩm
                        $stmt = $pdo->prepare(
                            "UPDATE products
                             SET stock = (
                                 SELECT COALESCE(SUM(stock), 0)
                                 FROM product_variants
                                 WHERE product_id = ?
                             )
                             WHERE id = ?"
                        );
                        $stmt->execute([$productId, $productId]);
                    } else {

                        // Sản phẩm không có biến thể
                        $stmt = $pdo->prepare(
                            "UPDATE products
                             SET stock = stock + ?
                             WHERE id = ?"
                        );
                        $stmt->execute([$quantity, $productId]);

                        if ($stmt->rowCount() === 0) {
                            $check = $pdo->prepare(
                                "SELECT id FROM products WHERE id = ?"
                            );
                            $check->execute([$productId]);

                            if (!$check->fetch()) {
                                throw new Exception(
                                    'Không tìm thấy sản phẩm cần hoàn kho.'
                                );
                            }
                        }
                    }

                    // Ghi nhận lịch sử hoàn kho
                    $stmtMovement = $pdo->prepare(
                        "INSERT INTO stock_movements
                         (
                            product_id,
                            variant_id,
                            movement_type,
                            quantity_change,
                            reference_code,
                            note
                         )
                         VALUES (?, ?, ?, ?, ?, ?)"
                    );

                    $stmtMovement->execute([
                        $productId,
                        !empty($variantId) ? (int)$variantId : null,
                        $status === 'cancelled' ? 'cancel' : 'returned',
                        $quantity,
                        $orderCode,
                        ($status === 'cancelled' ? 'Hủy đơn hàng' : 'Khách hoàn hàng')
                    ]);
                }
            }

            // Cập nhật trạng thái đơn hàng
            $stmt = $pdo->prepare(
                "UPDATE orders SET status = ? WHERE id = ?"
            );
            $stmt->execute([$status, $orderId]);

            $pdo->commit();

            $messages = [
                'confirmed' => 'Đã xác nhận đơn hàng.',
                'shipping' => 'Đơn hàng đang được giao.',
                'completed' => 'Đơn hàng đã hoàn thành.',
                'cancelled' => 'Đã hủy đơn hàng và hoàn kho thành công.',
                'failed_delivery' => 'Đã cập nhật đơn hàng giao thất bại. Chưa hoàn kho.',
                'returned' => 'Hàng đã hoàn về kho và được cộng lại tồn kho thành công.'
            ];

            flash($messages[$status] ?? 'Đã cập nhật trạng thái đơn hàng.');

            go('index.php?page=orders');


        case 'order':
            $pdo = db();

            $cart = $_SESSION['cart'] ?? [];

            if (!$cart) {
                throw new Exception('Giỏ hàng trống.');
            }

            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $note = trim($_POST['note'] ?? '');
            $payment = $_POST['payment'] ?? 'cod';

            if ($name === '' || $phone === '' || $address === '') {
                throw new Exception(
                    'Vui lòng nhập tên, số điện thoại và địa chỉ.'
                );
            }

            if (!in_array($payment, ['cod', 'bank', 'paypal'], true)) {
                throw new Exception('Phương thức thanh toán không hợp lệ.');
            }

            $pdo->beginTransaction();

            $subtotal = 0;
            $items = [];

            foreach ($cart as $item) {

                $id = (int)($item['product_id'] ?? 0);
                $qty = (int)($item['qty'] ?? 0);
                $variantId = (int)($item['variant_id'] ?? 0);

                if ($id <= 0 || $qty < 1) {
                    throw new Exception(
                        'Thông tin sản phẩm trong giỏ hàng không hợp lệ.'
                    );
                }

                if ($variantId > 0) {

                    // Lấy thông tin biến thể và sản phẩm mới nhất
                    $stmt = $pdo->prepare(
                        "SELECT
                            v.id AS variant_id,
                            v.product_id,
                            v.color,
                            v.size,
                            v.stock,
                            p.name,
                            p.price,
                            p.sale_price,
                            p.image,
                            p.sku,
                            p.is_active
                         FROM product_variants v
                         JOIN products p ON p.id = v.product_id
                         WHERE v.id = ?
                           AND v.product_id = ?
                           AND p.is_active = 1
                         FOR UPDATE"
                    );

                    $stmt->execute([$variantId, $id]);
                    $p = $stmt->fetch();

                    if (!$p) {
                        throw new Exception(
                            'Sản phẩm hoặc biến thể không còn tồn tại.'
                        );
                    }

                    if ((int)$p['stock'] < $qty) {
                        throw new Exception(
                            'Biến thể ' . $p['color'] . ' - ' . $p['size'] .
                                ' không đủ tồn kho. Hiện còn ' .
                                (int)$p['stock'] . ' sản phẩm.'
                        );
                    }

                    // Trừ kho biến thể
                    $stmt = $pdo->prepare(
                        "UPDATE product_variants
                         SET stock = stock - ?, updated_at = NOW()
                         WHERE id = ? AND stock >= ?"
                    );

                    $stmt->execute([$qty, $variantId, $qty]);

                    if ($stmt->rowCount() !== 1) {
                        throw new Exception(
                            'Không thể cập nhật tồn kho biến thể.'
                        );
                    }

                    // Cập nhật tồn kho tổng sản phẩm
                    $stmt = $pdo->prepare(
                        "UPDATE products
                         SET stock = (
                             SELECT COALESCE(SUM(stock), 0)
                             FROM product_variants
                             WHERE product_id = ?
                         )
                         WHERE id = ?"
                    );

                    $stmt->execute([$id, $id]);

                    $color = $p['color'];
                    $size = $p['size'];
                } else {

                    // Lấy thông tin sản phẩm mới nhất
                    $stmt = $pdo->prepare(
                        "SELECT *
                         FROM products
                         WHERE id = ? AND is_active = 1
                         FOR UPDATE"
                    );

                    $stmt->execute([$id]);
                    $p = $stmt->fetch();

                    if (!$p) {
                        throw new Exception(
                            'Sản phẩm không tồn tại hoặc đã ngừng bán.'
                        );
                    }

                    // Không cho đặt sản phẩm có biến thể khi chưa chọn màu/size
                    $stmt = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM product_variants
                         WHERE product_id = ?"
                    );

                    $stmt->execute([$id]);

                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new Exception(
                            'Sản phẩm ' . $p['name'] .
                                ' cần chọn màu và size trước khi đặt hàng.'
                        );
                    }

                    if ((int)$p['stock'] < $qty) {
                        throw new Exception(
                            'Sản phẩm ' . $p['name'] .
                                ' không đủ tồn kho. Hiện còn ' .
                                (int)$p['stock'] . ' sản phẩm.'
                        );
                    }

                    // Trừ kho sản phẩm
                    $stmt = $pdo->prepare(
                        "UPDATE products
                         SET stock = stock - ?
                         WHERE id = ? AND stock >= ?"
                    );

                    $stmt->execute([$qty, $id, $qty]);

                    if ($stmt->rowCount() !== 1) {
                        throw new Exception(
                            'Không thể cập nhật tồn kho sản phẩm.'
                        );
                    }


                    // Mã đơn hàng sẽ được cập nhật sau khi tạo đơn

                    $color = '';
                    $size = '';
                }

                // Luôn lấy giá mới nhất từ database
                $price = (
                    isset($p['sale_price']) &&
                    (float)$p['sale_price'] > 0
                )
                    ? (float)$p['sale_price']
                    : (float)$p['price'];

                if ($price <= 0) {
                    throw new Exception(
                        'Giá sản phẩm không hợp lệ.'
                    );
                }

                $subtotal += $price * $qty;

                $items[] = [
                    'product' => $p,
                    'product_id' => $id,
                    'variant_id' => $variantId,
                    'qty' => $qty,
                    'price' => $price,
                    'color' => $color,
                    'size' => $size
                ];
            }

            // Tính tiền đơn hàng
            $shipping = 0;
            $discount = 0;
            $total = $subtotal + $shipping - $discount;

            // Tạo mã đơn hàng
            $code = 'KB' . date('ymd') .
                strtoupper(bin2hex(random_bytes(3)));

            $stmt = $pdo->prepare(
                "INSERT INTO orders
                 (
                    user_id,
                    code,
                    customer_name,
                    phone,
                    email,
                    address,
                    note,
                    subtotal,
                    shipping_fee,
                    discount,
                    total,
                    payment_method,
                    payment_status,
                    status,
                    created_at
                 )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );

            $stmt->execute([
                $_SESSION['user']['id'] ?? null,
                $code,
                $name,
                $phone,
                $email ?: null,
                $address,
                $note ?: null,
                $subtotal,
                $shipping,
                $discount,
                $total,
                $payment,
                'unpaid',
                'pending'
            ]);

            $orderId = (int)$pdo->lastInsertId();

            // Lưu chi tiết sản phẩm trong đơn hàng
            foreach ($items as $item) {

                $p = $item['product'];

                $stmt = $pdo->prepare(
                    "INSERT INTO order_items
                     (
                        order_id,
                        product_id,
                        product_name,
                        sku,
                        price,
                        quantity,
                        color,
                        size,
                        image
                     )
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );

                $stmt->execute([
                    $orderId,
                    $item['product_id'],
                    $p['name'],
                    $p['sku'] ?? null,
                    $item['price'],
                    $item['qty'],
                    $item['color'],
                    $item['size'],
                    $p['image'] ?? null
                ]);

                // Ghi nhận lịch sử xuất kho do bán hàng
                $variantId = (int)($item['variant_id'] ?? 0);

                $stmtMovement = $pdo->prepare(
                    "INSERT INTO stock_movements
     (
        product_id,
        variant_id,
        movement_type,
        quantity_change,
        reference_code,
        note
     )
     VALUES (?, ?, ?, ?, ?, ?)"
                );

                $stmtMovement->execute([
                    $item['product_id'],
                    $variantId > 0 ? $variantId : null,
                    'sale',
                    -(int)$item['qty'],
                    $code,
                    'Bán hàng - Đơn ' . $code
                ]);
            }
            // Hoàn tất giao dịch
            $pdo->commit();

            $_SESSION['cart'] = [];
            $_SESSION['order'] = $code;

            go('index.php?page=success');


        default:
            throw new Exception('Yêu cầu không hợp lệ.');
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['error'] = $e->getMessage();

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id > 0) {
            go('index.php?page=edit_product&id=' . $id);
        }

        go('index.php?page=admin');
    }

    if ($action === 'delete_product_image') {
        $productId = (int)($_GET['product_id'] ?? 0);

        if ($productId > 0) {
            go('index.php?page=edit_product&id=' . $productId);
        }

        go('index.php?page=admin');
    }

    if ($action === 'login') go('index.php?page=login');

    go('index.php?page=cart');
}

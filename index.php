<?php
require __DIR__ . '/app/bootstrap.php';

$page = $_GET['page'] ?? 'home';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

$success = flash();

function cartCount(): int
{
    $cart = $_SESSION['cart'] ?? [];
    $count = 0;

    foreach ($cart as $item) {
        if (is_array($item)) {
            $count += (int)($item['qty'] ?? 0);
        } else {
            $count += (int)$item;
        }
    }

    return $count;
}

function productImage($product): string
{
    $image = $product['image'] ?? '';

    if (!$image && !empty($product['id'])) {
        $list = images((int)$product['id']);
        $image = $list[0] ?? '';
    }

    return $image ?: 'assets/placeholder.svg';
}

function displayPrice($product): float
{
    if (
        isset($product['sale_price']) &&
        $product['sale_price'] !== null &&
        (float)$product['sale_price'] > 0
    ) {
        return (float)$product['sale_price'];
    }

    return (float)$product['price'];
}

if ($page === 'admin' && !admin()) {
    $page = 'login';
}

if ($page === 'product') {
    $productId = (int)($_GET['id'] ?? 0);

    $product = null;
    $variants = [];
    $productImages = [];

    $stmt = db()->prepare(
        "SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$productId]);
    $product = $stmt->fetch();

    if ($product) {
        // Lấy danh sách biến thể
        $variantStmt = db()->prepare(
            "SELECT * FROM product_variants
             WHERE product_id = ?
             ORDER BY color, size"
        );
        $variantStmt->execute([$productId]);
        $variants = $variantStmt->fetchAll();

        // Lấy toàn bộ ảnh sản phẩm
        $imageStmt = db()->prepare(
            "SELECT id, path FROM product_images
             WHERE product_id = ?
             ORDER BY id ASC"
        );
        $imageStmt->execute([$productId]);
        $productImages = $imageStmt->fetchAll();

        // Nếu chưa có ảnh trong product_images thì dùng ảnh chính
        if (empty($productImages) && !empty($product['image'])) {
            $productImages[] = [
                'id' => 0,
                'path' => $product['image']
            ];
        }
    }
}

if ($page === 'home') {
    $keyword = trim($_GET['q'] ?? '');

    if ($keyword !== '') {
        $stmt = db()->prepare(
            "SELECT * FROM products
             WHERE is_active = 1
             AND (name LIKE ? OR sku LIKE ?)
             ORDER BY id DESC"
        );
        $search = '%' . $keyword . '%';
        $stmt->execute([$search, $search]);
        $products = $stmt->fetchAll();
    } else {
        $products = db()->query(
            "SELECT * FROM products
             WHERE is_active = 1
             ORDER BY id DESC"
        )->fetchAll();
    }
}

if (in_array($page, ['admin', 'products'], true) && admin()) {

    // Lọc sản phẩm theo tồn kho
    $stockFilter = $_GET['stock'] ?? 'all';

    $allowedStockFilters = [
        'all',
        'in_stock',
        'low_stock',
        'out_stock'
    ];

    if (!in_array($stockFilter, $allowedStockFilters, true)) {
        $stockFilter = 'all';
    }

    $whereSql = '';

    if ($stockFilter === 'in_stock') {
        $whereSql = " WHERE stock > 5";
    } elseif ($stockFilter === 'low_stock') {
        $whereSql = " WHERE stock BETWEEN 1 AND 5";
    } elseif ($stockFilter === 'out_stock') {
        $whereSql = " WHERE stock = 0";
    }

    // Đếm tổng sản phẩm theo bộ lọc
    $countStmt = db()->query(
        "SELECT COUNT(*) FROM products" . $whereSql
    );

    $totalProductRows = (int)$countStmt->fetchColumn();

    // Cấu hình phân trang
    $perPage = 10;
    $productCurrentPage = max(1, (int)($_GET['p'] ?? 1));

    $productTotalPages = max(
        1,
        (int)ceil($totalProductRows / $perPage)
    );

    $productCurrentPage = min(
        $productCurrentPage,
        $productTotalPages
    );

    $productOffset = ($productCurrentPage - 1) * $perPage;

    // Lấy danh sách sản phẩm
    $sql = "SELECT * FROM products" . $whereSql . " ORDER BY id DESC";

    // Chỉ phân trang ở trang danh sách sản phẩm
    if ($page === 'products') {
        $sql .= " LIMIT $perPage OFFSET $productOffset";
    }

    $adminProducts = db()->query($sql)->fetchAll();

    // Tổng doanh thu từ đơn hàng hoàn thành
    $revenue = db()->query(
        "SELECT COALESCE(SUM(total), 0)
         FROM orders
         WHERE status = 'completed'"
    )->fetchColumn();

    // Số đơn hàng hoàn thành
    $completedOrders = db()->query(
        "SELECT COUNT(*)
         FROM orders
         WHERE status = 'completed'"
    )->fetchColumn();

    // Số đơn đang xử lý
    $processingOrders = db()->query(
        "SELECT COUNT(*)
         FROM orders
         WHERE status IN ('pending', 'confirmed', 'shipping')"
    )->fetchColumn();

    // Số đơn bị hủy hoặc hoàn hàng
    $cancelledOrders = db()->query(
        "SELECT COUNT(*)
         FROM orders
         WHERE status IN ('cancelled', 'returned', 'failed_delivery')"
    )->fetchColumn();
    // Doanh thu 7 ngày gần nhất
    $chartStmt = db()->query(
        "SELECT DATE(created_at) AS order_date,
            SUM(total) AS daily_revenue
     FROM orders
     WHERE status = 'completed'
     AND created_at >= CURDATE() - INTERVAL 6 DAY
     AND created_at < CURDATE() + INTERVAL 1 DAY
     GROUP BY DATE(created_at)
     ORDER BY order_date ASC"
    );

    $chartRows = $chartStmt->fetchAll();

    $dailyRevenue = [];

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dailyRevenue[$date] = 0;
    }

    foreach ($chartRows as $row) {
        $dailyRevenue[$row['order_date']] = (float)$row['daily_revenue'];
    }
}
$editProduct = null;
$editVariants = [];
$editImages = [];
if ($page === 'edit_product' && admin()) {
    $editId = (int)($_GET['id'] ?? 0);

    $stmt = db()->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$editId]);
    $editProduct = $stmt->fetch();

    if ($editProduct) {
        $stmt = db()->prepare(
            "SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$editId]);
        $editVariants = $stmt->fetchAll();
        $stmt = db()->prepare(
            "SELECT id, path
     FROM product_images
     WHERE product_id = ?
     ORDER BY id ASC"
        );

        $stmt->execute([$editId]);
        $editImages = $stmt->fetchAll();
        // Nếu chưa có ảnh trong product_images, lấy ảnh chính từ products
        if (empty($editImages) && !empty($editProduct['image'])) {
            $editImages[] = [
                'id' => 0,
                'path' => $editProduct['image']
            ];
        }
    }
}
if ($page === 'orders' && admin()) {

    $keyword = trim($_GET['q'] ?? '');
    $orderStatus = $_GET['status'] ?? '';
    $perPage = (int)($_GET['per_page'] ?? 10);

    if (!in_array($perPage, [5, 10], true)) {
        $perPage = 10;
    }

    $currentPage = max(1, (int)($_GET['p'] ?? 1));

    $allowedStatuses = [
        'pending',
        'confirmed',
        'shipping',
        'failed_delivery',
        'returned',
        'completed',
        'cancelled'
    ];

    if (!in_array($orderStatus, $allowedStatuses, true)) {
        $orderStatus = '';
    }

    $sql = "SELECT * FROM orders WHERE 1=1";
    $params = [];

    // Lọc theo trạng thái
    if ($orderStatus !== '') {
        $sql .= " AND status = ?";
        $params[] = $orderStatus;
    }

    // Tìm theo mã đơn, tên hoặc số điện thoại
    if ($keyword !== '') {
        $sql .= " AND (
            code LIKE ?
            OR customer_name LIKE ?
            OR phone LIKE ?
        )";

        $search = '%' . $keyword . '%';

        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    // Đếm tổng số đơn hàng theo điều kiện lọc
    $countSql = "SELECT COUNT(*) FROM orders WHERE 1=1";

    $countParams = [];

    if ($orderStatus !== '') {
        $countSql .= " AND status = ?";
        $countParams[] = $orderStatus;
    }

    if ($keyword !== '') {
        $countSql .= " AND (
        code LIKE ?
        OR customer_name LIKE ?
        OR phone LIKE ?
    )";

        $search = '%' . $keyword . '%';

        $countParams[] = $search;
        $countParams[] = $search;
        $countParams[] = $search;
    }

    $countStmt = db()->prepare($countSql);
    $countStmt->execute($countParams);

    $totalOrders = (int)$countStmt->fetchColumn();

    // Tính số trang
    $totalPages = max(1, (int)ceil($totalOrders / $perPage));

    $currentPage = min($currentPage, $totalPages);

    // Tính vị trí bắt đầu
    $offset = ($currentPage - 1) * $perPage;

    // Lấy danh sách đơn hàng của trang hiện tại
    $sql .= " ORDER BY id DESC LIMIT $perPage OFFSET $offset";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $orders = $stmt->fetchAll();
}

if ($page === 'order_detail' && admin()) {
    $orderId = (int)($_GET['id'] ?? 0);

    $orderDetail = null;
    $orderItems = [];

    if ($orderId > 0) {
        $stmt = db()->prepare(
            "SELECT * FROM orders WHERE id = ? LIMIT 1"
        );
        $stmt->execute([$orderId]);
        $orderDetail = $stmt->fetch();

        if ($orderDetail) {
            $stmt = db()->prepare(
                "SELECT * FROM order_items
                 WHERE order_id = ?
                 ORDER BY id ASC"
            );
            $stmt->execute([$orderId]);
            $orderItems = $stmt->fetchAll();
        }
    }
}

if ($page === 'cart') {
    $cartProducts = [];

    foreach ($_SESSION['cart'] ?? [] as $key => $item) {
        if (!is_array($item)) {
            continue;
        }

        $cartProducts[$key] = $item;
    }
}
if ($page === 'customer_detail' && admin()) {

    $customerPhone = trim($_GET['phone'] ?? '');

    $customerDetail = null;
    $customerOrders = [];
    $latestCustomerOrder = null;

    if ($customerPhone !== '') {

        // Lấy thông tin khách hàng
        $stmt = db()->prepare(
            "SELECT
    phone,
    MAX(customer_name) AS customer_name,
    MAX(email) AS email,
    COUNT(*) AS total_orders,

    SUM(CASE
        WHEN status = 'completed' THEN 1
        ELSE 0
    END) AS completed_orders,

    SUM(CASE
        WHEN status = 'pending' THEN 1
        ELSE 0
    END) AS pending_orders,

    SUM(CASE
        WHEN status = 'cancelled' THEN 1
        ELSE 0
    END) AS cancelled_orders,

    SUM(CASE
        WHEN status = 'completed' THEN total
        ELSE 0
    END) AS total_spent,

    MIN(created_at) AS first_order,
    MAX(created_at) AS last_order

FROM orders
WHERE phone = ?
GROUP BY phone
LIMIT 1"
        );

        $stmt->execute([$customerPhone]);
        $customerDetail = $stmt->fetch();

        // Lấy toàn bộ đơn hàng của khách
        if ($customerDetail) {

            $statusFilter = trim($_GET['status'] ?? '');

            $sql = "SELECT *
        FROM orders
        WHERE phone = ?";

            $params = [$customerPhone];

            $allowedStatuses = [
                'pending',
                'processing',
                'shipping',
                'completed',
                'cancelled'
            ];

            if (in_array($statusFilter, $allowedStatuses, true)) {
                $sql .= " AND status = ?";
                $params[] = $statusFilter;
            }

            $sql .= " ORDER BY created_at DESC";

            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $customerOrders = $stmt->fetchAll();
            // Lấy địa chỉ từ đơn hàng gần nhất
            $stmt = db()->prepare(
                "SELECT address, note, payment_method, created_at
     FROM orders
     WHERE phone = ?
     ORDER BY created_at DESC, id DESC
     LIMIT 1"
            );

            $stmt->execute([$customerPhone]);
            $latestCustomerOrder = $stmt->fetch();
        }
    }
}

if ($page === 'stock_import' && admin()) {

    // Lấy danh sách sản phẩm
    $importProducts = db()->query(
        "SELECT id, name
         FROM products
         ORDER BY name ASC"
    )->fetchAll();

    // Lấy danh sách biến thể
    $importVariants = db()->query(
        "SELECT
            pv.id,
            pv.product_id,
            pv.color,
            pv.size,
            pv.stock,
            pv.sku,
            p.name AS product_name
         FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id
         ORDER BY p.name ASC, pv.color ASC, pv.size ASC"
    )->fetchAll();
}

if ($page === 'stock_history' && admin()) {

    $historySearch = trim($_GET['q'] ?? '');
    $historyFrom = $_GET['from'] ?? '';
    $historyTo = $_GET['to'] ?? '';

    // Kiểm tra định dạng ngày
    if (
        $historyFrom !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyFrom)
    ) {
        $historyFrom = '';
    }

    if (
        $historyTo !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $historyTo)
    ) {
        $historyTo = '';
    }

    $where = [];
    $params = [];

    if ($historySearch !== '') {
        $where[] = "(
            p.name LIKE ?
            OR pv.sku LIKE ?
            OR pv.color LIKE ?
            OR pv.size LIKE ?
            OR si.note LIKE ?
        )";

        $keyword = '%' . $historySearch . '%';

        $params = array_merge(
            $params,
            [$keyword, $keyword, $keyword, $keyword, $keyword]
        );
    }

    if ($historyFrom !== '') {
        $where[] = "DATE(si.created_at) >= ?";
        $params[] = $historyFrom;
    }

    if ($historyTo !== '') {
        $where[] = "DATE(si.created_at) <= ?";
        $params[] = $historyTo;
    }

    $whereSql = $where
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

    // Tổng số bản ghi
    $stmt = db()->prepare(
        "SELECT COUNT(*)
         FROM stock_imports si
         INNER JOIN products p ON p.id = si.product_id
         LEFT JOIN product_variants pv ON pv.id = si.variant_id
         $whereSql"
    );

    $stmt->execute($params);

    $historyTotal = (int)$stmt->fetchColumn();
    // Tổng số lượng hàng đã nhập theo bộ lọc
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(si.quantity), 0)
     FROM stock_imports si
     INNER JOIN products p ON p.id = si.product_id
     LEFT JOIN product_variants pv ON pv.id = si.variant_id
     $whereSql"
    );

    $stmt->execute($params);

    $historyTotalQuantity = (int)$stmt->fetchColumn();
    // Thống kê nhập kho theo sản phẩm
    $stmt = db()->prepare(
        "SELECT
        p.id,
        p.name AS product_name,
        COUNT(si.id) AS import_count,
        SUM(si.quantity) AS total_quantity
     FROM stock_imports si
     INNER JOIN products p ON p.id = si.product_id
     LEFT JOIN product_variants pv ON pv.id = si.variant_id
     $whereSql
     GROUP BY p.id, p.name
     ORDER BY total_quantity DESC"
    );

    $stmt->execute($params);

    $historyByProduct = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Phân trang
    $historyPerPage = 10;
    $historyPage = max(1, (int)($_GET['p'] ?? 1));

    $historyTotalPages = max(
        1,
        (int)ceil($historyTotal / $historyPerPage)
    );

    $historyPage = min($historyPage, $historyTotalPages);

    $historyOffset = ($historyPage - 1) * $historyPerPage;

    // Lấy lịch sử nhập kho
    $stmt = db()->prepare(
        "SELECT
            si.id,
            si.quantity,
            si.note,
            si.created_at,
            p.name AS product_name,
            pv.color,
            pv.size,
            pv.sku
         FROM stock_imports si
         INNER JOIN products p ON p.id = si.product_id
         LEFT JOIN product_variants pv ON pv.id = si.variant_id
         $whereSql
         ORDER BY si.id DESC
         LIMIT $historyPerPage OFFSET $historyOffset"
    );

    $stmt->execute($params);

    $stockImportHistory = $stmt->fetchAll();
}
if ($page === 'stock_import_detail' && admin()) {

    $importId = (int)($_GET['id'] ?? 0);

    $stmt = db()->prepare(
        "SELECT
            si.id,
            si.product_id,
            si.variant_id,
            si.quantity,
            si.note,
            si.created_at,
            p.name AS product_name,
            pv.sku,
            pv.color,
            pv.size
         FROM stock_imports si
         INNER JOIN products p ON p.id = si.product_id
         LEFT JOIN product_variants pv ON pv.id = si.variant_id
         WHERE si.id = ?
         LIMIT 1"
    );

    $stmt->execute([$importId]);

    $stockImportDetail = $stmt->fetch(PDO::FETCH_ASSOC);
}
if ($page === 'stock_management' && admin()) {

    // Nhận từ khóa tìm kiếm
    $stockSearch = trim($_GET['q'] ?? '');

    // Nhận bộ lọc tồn kho
    $stockStatusFilter = $_GET['status'] ?? 'all';

    $allowedStockStatuses = [
        'all',
        'in_stock',
        'low_stock',
        'out_stock'
    ];

    if (!in_array($stockStatusFilter, $allowedStockStatuses, true)) {
        $stockStatusFilter = 'all';
    }

    // Điều kiện tìm kiếm
    $where = [];
    $params = [];

    if ($stockSearch !== '') {
        $where[] = "(
            p.name LIKE ?
            OR pv.sku LIKE ?
            OR pv.color LIKE ?
            OR pv.size LIKE ?
        )";

        $keyword = '%' . $stockSearch . '%';

        $params = [
            $keyword,
            $keyword,
            $keyword,
            $keyword
        ];
    }

    // Điều kiện lọc tồn kho
    if ($stockStatusFilter === 'in_stock') {
        $where[] = "pv.stock > 5";
    } elseif ($stockStatusFilter === 'low_stock') {
        $where[] = "pv.stock BETWEEN 1 AND 5";
    } elseif ($stockStatusFilter === 'out_stock') {
        $where[] = "pv.stock <= 0";
    }

    $whereSql = $where
        ? ' WHERE ' . implode(' AND ', $where)
        : '';

    // Đếm tổng số biến thể sau khi tìm kiếm và lọc
    $stmt = db()->prepare(
        "SELECT COUNT(*)
     FROM product_variants pv
     INNER JOIN products p ON p.id = pv.product_id
     $whereSql"
    );

    $stmt->execute($params);

    $stockManagementTotal = (int)$stmt->fetchColumn();

    // Cấu hình phân trang
    $stockManagementPerPage = 10;

    $stockManagementPage = max(1, (int)($_GET['p'] ?? 1));

    $stockManagementTotalPages = max(
        1,
        (int)ceil($stockManagementTotal / $stockManagementPerPage)
    );

    $stockManagementPage = min(
        $stockManagementPage,
        $stockManagementTotalPages
    );

    $stockManagementOffset = (
        $stockManagementPage - 1
    ) * $stockManagementPerPage;

    // Lấy danh sách tồn kho theo trang
    $stmt = db()->prepare(
        "SELECT
        pv.id AS variant_id,
        pv.product_id,
        pv.color,
        pv.size,
        pv.sku,
        pv.stock,
        p.name AS product_name
     FROM product_variants pv
     INNER JOIN products p ON p.id = pv.product_id
     $whereSql
     ORDER BY p.name ASC, pv.color ASC, pv.size ASC
     LIMIT $stockManagementPerPage
     OFFSET $stockManagementOffset"
    );

    $stmt->execute($params);

    $stockManagementVariants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Thống kê tổng quan toàn bộ kho
    $stockManagementStats = db()->query(
        "SELECT
            COALESCE(SUM(stock), 0) AS total_stock,
            COALESCE(SUM(CASE WHEN stock BETWEEN 1 AND 5 THEN 1 ELSE 0 END), 0) AS low_stock,
            COALESCE(SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END), 0) AS out_stock
         FROM product_variants"
    )->fetch(PDO::FETCH_ASSOC);
}
// Lấy dữ liệu cho trang điều chỉnh tồn kho
if ($page === 'stock_adjustment' && admin()) {

    $stmt = db()->query(
        "SELECT
            pv.id AS variant_id,
            pv.product_id,
            pv.color,
            pv.size,
            pv.sku,
            pv.stock,
            p.name AS product_name
         FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id
         ORDER BY p.name ASC, pv.color ASC, pv.size ASC"
    );

    $stockAdjustmentVariants = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($page === 'stock_movements' && admin()) {

    $stmt = db()->query(
        "SELECT
        sm.id,
        sm.movement_type,
        sm.quantity_change,
        sm.reference_code,
        sm.note,
        sm.created_at,
        p.name AS product_name,
        p.sku,
        pv.color,
        pv.size
     FROM stock_movements sm
     INNER JOIN products p ON p.id = sm.product_id
     LEFT JOIN product_variants pv ON pv.id = sm.variant_id
     ORDER BY sm.id DESC"
    );

    $stockMovements = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($page === 'success') {
    $orderCode = $_SESSION['order'] ?? '';
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KingBoxer - Shop đồ lót nam</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            font-family: Arial, sans-serif;
            background: #f6f6f6;
            color: #222;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .topbar {
            background: #111;
            color: white;
            padding: 15px 5%;
        }

        .topbar-inner {
            max-width: 1200px;
            margin: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .logo {
            font-size: 25px;
            font-weight: bold;
            letter-spacing: 2px;
        }

        .nav {
            display: flex;
            gap: 18px;
            align-items: center;
            flex-wrap: wrap;
        }

        .nav a:hover {
            color: #ffcc00;
        }

        .search-form {
            display: flex;
            gap: 5px;
        }

        .search-form input {
            padding: 10px;
            border: 0;
            border-radius: 4px;
            min-width: 180px;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 15px;
        }

        .title {
            font-size: 25px;
            margin-bottom: 20px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
        }

        .product-card {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #eee;
        }

        .product-card img {
            width: 100%;
            height: 240px;
            object-fit: cover;
            background: #eee;
        }

        .product-info {
            padding: 15px;
        }

        .product-name {
            font-weight: bold;
            min-height: 40px;
            margin-bottom: 10px;
        }

        .price {
            color: #d60000;
            font-size: 19px;
            font-weight: bold;
            margin: 10px 0;
        }

        .old-price {
            color: #888;
            text-decoration: line-through;
            font-size: 14px;
            margin-left: 8px;
        }

        .btn {
            display: inline-block;
            background: #111;
            color: white;
            border: 0;
            border-radius: 5px;
            padding: 11px 16px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn:hover {
            background: #d60000;
        }

        .btn-yellow {
            background: #ffcc00;
            color: #111;
        }

        .btn-danger {
            background: #c62828;
        }

        .form-box {
            max-width: 750px;
            margin: auto;
            background: white;
            padding: 25px;
            border-radius: 8px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: 15px;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        .message {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 18px;
            background: #fff3cd;
            color: #664d03;
        }

        .success-message {
            background: #d1e7dd;
            color: #0f5132;
        }

        .detail-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            background: white;
            padding: 25px;
            border-radius: 8px;
        }

        .detail-image {
            width: 100%;
            max-height: 500px;
            object-fit: contain;
            background: #f5f5f5;
        }

        .product-description {
            max-height: 150px;
            overflow-y: auto;
            padding: 10px;
            margin: 10px 0 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fafafa;
            line-height: 1.6;
            font-size: 14px;
        }

        .product-gallery {
            width: 100%;
            text-align: center;
        }

        .detail-image {
            width: 100%;
            height: 500px;
            object-fit: contain;
            background: #f5f5f5;
            display: block;
        }

        .product-thumbnails {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .gallery-thumb {
            width: 70px !important;
            height: 70px !important;
            object-fit: cover !important;
            border: 2px solid #ddd;
            border-radius: 5px;
            cursor: pointer;
            background: #fff;
        }

        .gallery-thumb:hover,
        .gallery-thumb.active {
            border-color: #111;
        }

        .variant-row {
            display: grid;
            grid-template-columns: 1.2fr 1fr 0.8fr 1.2fr auto;
            gap: 8px;
            margin-bottom: 10px;
            align-items: center;
        }

        .variant-row input {
            min-width: 0;
        }

        .variant-row button {
            padding: 10px;
            border: 0;
            background: #c62828;
            color: white;
            border-radius: 4px;
            cursor: pointer;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #eee;
        }

        .cart-item {
            display: grid;
            grid-template-columns: 90px 1fr 100px;
            gap: 15px;
            padding: 15px;
            background: white;
            border-bottom: 1px solid #eee;
            align-items: center;
        }

        .cart-item img {
            width: 85px;
            height: 85px;
            object-fit: cover;
        }

        .footer {
            margin-top: auto;
            padding: 25px;
            background: #111;
            color: white;
            text-align: center;
            flex-shrink: 0;
        }

        body>main.container {
            flex: 1 0 auto;
        }

        /* Chỉ áp dụng cho trang chi tiết sản phẩm */
        body:has(.detail-layout) {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        body:has(.detail-layout) main.container {
            flex: 1;
        }

        body:has(.detail-layout) footer.footer {
            margin-top: auto;
        }

        @media(max-width: 700px) {
            .detail-layout {
                grid-template-columns: 1fr;
            }

            .variant-row {
                grid-template-columns: 1fr 1fr;
            }

            .cart-item {
                grid-template-columns: 70px 1fr;
            }

            .cart-item img {
                width: 65px;
                height: 65px;
            }
        }


        /* ==========================
           FIX: KINGBOXER HOMEPAGE
           Keep homepage styles available even if external CSS is not loaded.
        ========================== */

        /* ==========================
           KINGBOXER HOMEPAGE
        ========================== */

        .home-hero {
            min-height: 390px;
            background: #151515;
            color: #fff;
            padding: 55px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
            overflow: hidden;
        }

        .home-hero-content {
            max-width: 650px;
        }

        .home-hero-label,
        .home-eyebrow {
            font-size: 12px;
            letter-spacing: 3px;
            font-weight: bold;
        }

        .home-hero-label {
            color: #d6b65b;
        }

        .home-hero h1 {
            font-size: clamp(32px, 5vw, 58px);
            line-height: 1.15;
            margin: 22px 0;
            letter-spacing: 1px;
        }

        .home-hero p {
            color: #ccc;
            line-height: 1.8;
            max-width: 480px;
        }

        .home-hero-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 15px 25px;
            background: #d6b65b;
            color: #111;
            font-weight: bold;
            font-size: 13px;
            transition: .2s;
        }

        .home-hero-btn:hover {
            background: #fff;
        }

        .home-hero-side {
            text-align: right;
            color: #d6b65b;
        }

        .home-hero-side span {
            letter-spacing: 5px;
            font-size: 12px;
        }

        .home-hero-side strong {
            display: block;
            font-size: clamp(55px, 9vw, 110px);
            line-height: .95;
            letter-spacing: -5px;
        }

        .home-hero-side small {
            display: block;
            margin-top: 20px;
            letter-spacing: 3px;
            color: #aaa;
        }

        .home-benefits {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            background: #fff;
            padding: 25px 4%;
            gap: 15px;
            margin-bottom: 55px;
        }

        .home-benefits div {
            display: flex;
            align-items: center;
            gap: 12px;
            justify-content: center;
        }

        .home-benefits strong {
            color: #c4a34d;
            font-size: 23px;
        }

        .home-benefits span {
            font-size: 13px;
            font-weight: bold;
        }

        .home-products-section {
            padding-bottom: 55px;
            scroll-margin-top: 25px;
        }

        .home-section-heading {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 28px;
        }

        .home-eyebrow {
            color: #a58a43;
        }

        .home-section-heading h2 {
            font-size: 27px;
            margin: 12px 0 8px;
        }

        .home-section-heading p {
            color: #777;
            margin: 0;
        }

        .home-product-count {
            color: #777;
            font-size: 13px;
        }

        .home-product-image {
            position: relative;
            overflow: hidden;
            background: #eee;
        }

        .home-product-image img {
            width: 100%;
            height: 270px;
            object-fit: cover;
            display: block;
            transition: transform .35s;
        }

        .product-card:hover .home-product-image img {
            transform: scale(1.05);
        }

        .home-sale-label {
            position: absolute;
            top: 12px;
            left: 12px;
            background: #c7a64d;
            color: #111;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: bold;
        }

        .home-empty {
            padding: 50px 20px;
            text-align: center;
            background: #fff;
        }

        .home-brand {
            background: #e9e9e9;
            padding: 55px 8%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 25px;
            margin-bottom: 40px;
        }

        .home-brand>div:first-child {
            max-width: 500px;
        }

        .home-brand span {
            color: #987c36;
            font-size: 12px;
            letter-spacing: 3px;
            font-weight: bold;
        }

        .home-brand h2 {
            font-size: 43px;
            line-height: 1.1;
            margin: 18px 0;
        }

        .home-brand p {
            color: #555;
            line-height: 1.8;
        }

        .home-brand a {
            display: inline-block;
            margin-top: 15px;
            font-weight: bold;
            border-bottom: 2px solid #111;
            padding-bottom: 7px;
        }

        .home-brand-number {
            font-size: clamp(90px, 18vw, 210px);
            font-weight: 900;
            color: #d0d0d0;
            letter-spacing: -12px;
        }

        .home-brand-number span {
            font-size: 20px;
            vertical-align: top;
            color: #999;
        }

        @media (max-width: 700px) {
            .home-hero {
                min-height: 330px;
                padding: 40px 22px;
            }

            .home-hero-side {
                display: none;
            }

            .home-benefits {
                grid-template-columns: repeat(2, 1fr);
                padding: 22px 10px;
                gap: 20px 8px;
                margin-bottom: 35px;
            }

            .home-benefits div {
                justify-content: flex-start;
            }

            .home-benefits span {
                font-size: 12px;
            }

            .home-section-heading h2 {
                font-size: 21px;
            }

            .home-product-image img {
                height: 190px;
            }

            .home-brand {
                padding: 35px 22px;
            }

            .home-brand-number {
                display: none;
            }

            .home-brand h2 {
                font-size: 35px;
            }
        }
    </style>
</head>

<body>

    <header class="topbar">
        <div class="topbar-inner">
            <a class="logo" href="index.php">KINGBOXER</a>

            <form class="search-form" action="index.php" method="get">
                <input type="hidden" name="page" value="home">
                <input
                    type="text"
                    name="q"
                    placeholder="Tìm sản phẩm..."
                    value="<?= e($_GET['q'] ?? '') ?>">
                <button class="btn btn-yellow" type="submit">Tìm</button>
            </form>

            <nav class="nav">
                <a href="index.php">Trang chủ</a>
                <a href="index.php?page=cart">Giỏ hàng (<?= cartCount() ?>)</a>

                <?php if (!empty($_SESSION['user'])): ?>
                    <?php if (admin()): ?>
                        <a href="index.php?page=admin">Quản trị</a>
                        <a href="index.php?page=orders">Quản lý đơn hàng</a>
                    <?php endif; ?>
                    <a href="actions.php?action=logout">Đăng xuất</a>
                <?php else: ?>
                    <a href="index.php?page=login">Đăng nhập</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <?php
    $isAdminPage = admin() && in_array($page, [
        'admin',
        'products',
        'add_product',
        'edit_product',
        'orders',
        'order_detail',
        'customers',
        'customer_detail',
        'stock_import',
        'stock_management',
        'stock_adjustment',
        'stock_movements',
        'stock_history'

    ], true);
    ?>

    <?php if ($isAdminPage): ?>
        <div class="admin-layout">
            <aside class="admin-sidebar">
                <h2>KINGBOXER</h2>
                <p>QUẢN TRỊ HỆ THỐNG</p>

                <a href="index.php?page=admin"
                    class="<?= $page === 'admin' ? 'active' : '' ?>">
                    📊 Tổng quan
                </a>

                <a href="index.php?page=products"
                    class="<?= $page === 'products' ? 'active' : '' ?>">
                    📦 Sản phẩm
                </a>

                <a href="index.php?page=stock_import"
                    class="<?= $page === 'stock_import' ? 'active' : '' ?>">
                    📥 Nhập kho
                </a>
                <a href="index.php?page=stock_history"
                    class="<?= $page === 'stock_history' ? 'active' : '' ?>">
                    📋 Lịch sử nhập kho
                </a>
                <a href="index.php?page=stock_management"
                    class="<?= $page === 'stock_management' ? 'active' : '' ?>">
                    📦 Quản lý tồn kho
                </a>
                <a href="index.php?page=stock_adjustment"
                    class="<?= $page === 'stock_adjustment' ? 'active' : '' ?>">
                    🛠️ Điều chỉnh tồn kho
                </a>
                <a href="index.php?page=stock_movements"
                    class="<?= $page === 'stock_movements' ? 'active' : '' ?>">
                    📊 Lịch sử biến động kho
                </a>
                <a href="index.php?page=add_product"
                    class="<?= $page === 'add_product' ? 'active' : '' ?>">
                    ➕ Thêm sản phẩm
                </a>

                <a href="index.php?page=orders"
                    class="<?= in_array($page, ['orders', 'order_detail'], true) ? 'active' : '' ?>">
                    🛒 Đơn hàng
                </a>

                <a href="index.php?page=customers"
                    class="<?= $page === 'customers' ? 'active' : '' ?>">
                    👥 Khách hàng
                </a>

                <a href="actions.php?action=logout" class="logout">
                    🚪 Đăng xuất
                </a>
            </aside>

            <div class="admin-content">
            <?php endif; ?>

            <main class="container">

                <?php if ($error): ?>
                    <div class="message"><?= e($error) ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="message success-message"><?= e($success) ?></div>
                <?php endif; ?>


                <?php if ($page === 'home'): ?>

                    <!-- BANNER CHÍNH -->
                    <section class="home-hero">
                        <div class="home-hero-content">
                            <span class="home-hero-label">KINGBOXER OFFICIAL</span>
                            <h1>THOẢI MÁI<br>TRONG TỪNG CHUYỂN ĐỘNG</h1>
                            <p>Đồ lót nam hiện đại – mềm mại, thoáng mát và co giãn linh hoạt.</p>
                            <a href="#home-products" class="home-hero-btn">KHÁM PHÁ SẢN PHẨM</a>
                        </div>
                        <div class="home-hero-side">
                            <span>COMFORT</span>
                            <strong>EVERY<br>DAY.</strong>
                            <small>DESIGNED FOR MEN</small>
                        </div>
                    </section>

                    <!-- ƯU ĐIỂM -->
                    <section class="home-benefits">
                        <div>
                            <strong>01</strong>
                            <span>Chất liệu mềm mại</span>
                        </div>
                        <div>
                            <strong>02</strong>
                            <span>Co giãn linh hoạt</span>
                        </div>
                        <div>
                            <strong>03</strong>
                            <span>Thoáng mát cả ngày</span>
                        </div>
                        <div>
                            <strong>04</strong>
                            <span>Đặt hàng tiện lợi</span>
                        </div>
                    </section>

                    <!-- TIÊU ĐỀ SẢN PHẨM -->
                    <section class="home-products-section" id="home-products">
                        <div class="home-section-heading">
                            <div>
                                <span class="home-eyebrow">KINGBOXER COLLECTION</span>
                                <h2>SẢN PHẨM CỦA CHÚNG TÔI</h2>
                                <p>Lựa chọn phù hợp cho sự thoải mái mỗi ngày.</p>
                            </div>
                            <span class="home-product-count">
                                <?= count($products) ?> sản phẩm
                            </span>
                        </div>

                        <?php if (empty($products)): ?>
                            <div class="home-empty">
                                <h3>Chưa có sản phẩm nào</h3>
                                <p>Cậu hãy đăng nhập quản trị để thêm sản phẩm nhé.</p>
                            </div>
                        <?php else: ?>

                            <div class="product-grid">
                                <?php foreach ($products as $p): ?>
                                    <a class="product-card" href="index.php?page=product&id=<?= (int)$p['id'] ?>">

                                        <div class="home-product-image">
                                            <img src="<?= e(productImage($p)) ?>" alt="<?= e($p['name']) ?>" onerror="this.onerror=null;this.src='assets/placeholder.svg';">

                                            <?php if (!empty($p['sale_price']) && $p['sale_price'] > 0 && $p['sale_price'] < $p['price']): ?>
                                                <span class="home-sale-label">SALE</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="product-info">
                                            <div class="product-name"><?= e($p['name']) ?></div>

                                            <div class="price">
                                                <?= money(displayPrice($p)) ?>

                                                <?php if (!empty($p['sale_price']) && $p['sale_price'] > 0 && $p['sale_price'] < $p['price']): ?>
                                                    <span class="old-price"><?= money($p['price']) ?></span>
                                                <?php endif; ?>
                                            </div>

                                            <span class="btn">XEM SẢN PHẨM</span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                        <?php endif; ?>
                    </section>

                    <!-- BANNER THƯƠNG HIỆU -->
                    <section class="home-brand">
                        <div>
                            <span>THE KINGBOXER STANDARD</span>
                            <h2>ĐƠN GIẢN.<br>THOẢI MÁI.<br>TỰ TIN.</h2>
                            <p>KingBoxer hướng đến những sản phẩm thiết yếu, mang lại cảm giác dễ chịu trong từng ngày.</p>
                            <a href="#home-products">MUA SẮM NGAY →</a>
                        </div>
                        <div class="home-brand-number">KB<span>®</span></div>
                    </section>

                <?php elseif ($page === 'product'): ?>


                    <?php if (empty($product)): ?>
                        <div class="message">Không tìm thấy sản phẩm.</div>
                    <?php else: ?>


                        <div class="detail-layout">

                            <!-- CỘT BÊN TRÁI: ẢNH SẢN PHẨM -->
                            <div class="product-gallery">

                                <?php if (!empty($productImages)): ?>

                                    <img
                                        id="mainProductImage"
                                        class="detail-image"
                                        src="<?= e($productImages[0]['path']) ?>"
                                        alt="<?= e($product['name']) ?>">

                                    <?php if (count($productImages) > 1): ?>
                                        <div class="product-thumbnails">
                                            <?php foreach ($productImages as $index => $image): ?>
                                                <img
                                                    src="<?= e($image['path']) ?>"
                                                    class="gallery-thumb <?= $index === 0 ? 'active' : '' ?>"
                                                    onclick="changeProductImage(this)"
                                                    alt="<?= e($product['name']) ?>">
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>

                                    <img
                                        class="detail-image"
                                        src="<?= e(productImage($product)) ?>"
                                        alt="<?= e($product['name']) ?>">

                                <?php endif; ?>

                            </div>

                            <!-- CỘT BÊN PHẢI: THÔNG TIN VÀ ĐẶT HÀNG -->
                            <div class="detail-info">

                                <h1><?= e($product['name']) ?></h1>

                                <div class="price">
                                    <?= money(displayPrice($product)) ?>
                                </div>

                                <?php if (!empty($product['sku'])): ?>
                                    <p>Mã sản phẩm: <?= e($product['sku']) ?></p>
                                <?php endif; ?>

                                <div class="product-description">
                                    <?= nl2br(e($product['description'] ?? '')) ?>
                                </div>
                                <?php if (!empty($variants)): ?>

                                    <form action="actions.php" method="post">
                                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">

                                        <div class="form-group">
                                            <label>Chọn màu sắc và kích cỡ</label>
                                            <select name="variant_id" required>
                                                <option value="">-- Chọn phân loại --</option>

                                                <?php foreach ($variants as $v): ?>
                                                    <option
                                                        value="<?= (int)$v['id'] ?>"
                                                        <?= (int)$v['stock'] <= 0 ? 'disabled' : '' ?>>
                                                        <?= e($v['color']) ?>
                                                        - Size <?= e($v['size']) ?>
                                                        - Còn <?= (int)$v['stock'] ?> sản phẩm
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="form-group">
                                            <label>Số lượng</label>
                                            <input type="number" name="qty" value="1" min="1" required>
                                        </div>

                                        <button class="btn" type="submit">Thêm vào giỏ hàng</button>
                                    </form>

                                <?php else: ?>

                                    <p>
                                        Tồn kho:
                                        <strong><?= (int)$product['stock'] ?></strong>
                                    </p>

                                    <form action="actions.php" method="post">
                                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="id" value="<?= (int)$product['id'] ?>">
                                        <input type="hidden" name="variant_id" value="0">

                                        <div class="form-group">
                                            <label>Số lượng</label>
                                            <input type="number" name="qty" value="1" min="1"
                                                max="<?= (int)$product['stock'] ?>" required>
                                        </div>

                                        <button class="btn" type="submit">Thêm vào giỏ hàng</button>
                                    </form>

                                <?php endif; ?>

                            </div>
                        </div>


                    <?php endif; ?>

                <?php elseif ($page === 'login'): ?>

                    <div class="form-box">
                        <h1 class="title">Đăng nhập quản trị</h1>

                        <form action="actions.php" method="post">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="action" value="login">

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" name="email" required>
                            </div>

                            <div class="form-group">
                                <label>Mật khẩu</label>
                                <input type="password" name="password" required>
                            </div>

                            <button class="btn" type="submit">Đăng nhập</button>
                        </form>
                    </div>

                <?php elseif ($page === 'admin' && admin()): ?>

                    <h1 class="title">Quản trị KingBoxer</h1>
                    <div style="
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(200px,1fr));
    gap:15px;
    margin-bottom:25px;
">

                        <div class="form-box">
                            <p>Tổng doanh thu</p>
                            <h2 style="color:#16a34a;">
                                <?= number_format((float)$revenue, 0, ',', '.') ?> đ
                            </h2>
                        </div>

                        <div class="form-box">
                            <p>Đơn hoàn thành</p>
                            <h2><?= (int)$completedOrders ?></h2>
                        </div>

                        <div class="form-box">
                            <p>Đơn đang xử lý</p>
                            <h2 style="color:#2563eb;">
                                <?= (int)$processingOrders ?>
                            </h2>
                        </div>

                        <div class="form-box">
                            <p>Đơn hủy / hoàn hàng</p>
                            <h2 style="color:#dc2626;">
                                <?= (int)$cancelledOrders ?>
                            </h2>
                        </div>

                        <div class="form-box revenue-chart">
                            <h2>Doanh thu 7 ngày gần nhất</h2>

                            <?php
                            $maxChartRevenue = !empty($dailyRevenue)
                                ? max(1, (float) max($dailyRevenue))
                                : 1;

                            $totalChartRevenue = array_sum($dailyRevenue);
                            ?>

                            <div class="revenue-chart-bars">
                                <?php foreach ($dailyRevenue as $date => $amount): ?>
                                    <?php
                                    $height = $amount > 0
                                        ? max(6, ($amount / $maxChartRevenue) * 190)
                                        : 0;

                                    $percent = $totalChartRevenue > 0
                                        ? ($amount / $totalChartRevenue) * 100
                                        : 0;
                                    ?>

                                    <div class="revenue-chart-item">
                                        <strong class="revenue-chart-value">
                                            <?= number_format($amount, 0, ',', '.') ?> đ
                                        </strong>

                                        <div class="revenue-chart-track">
                                            <div
                                                class="revenue-chart-bar"
                                                style="height: <?= $height ?>px;">
                                            </div>
                                        </div>

                                        <strong class="revenue-chart-date">
                                            <?= date('d/m', strtotime($date)) ?>
                                        </strong>

                                        <span class="revenue-chart-percent">
                                            <?= number_format($percent, 1) ?>%
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="revenue-chart-total">
                                Tổng doanh thu:
                                <strong><?= number_format($totalChartRevenue, 0, ',', '.') ?> đ</strong>
                            </div>
                        </div>

                    </div>

                <?php elseif ($page === 'add_product' && admin()): ?>

                    <div class="form-box" style="max-width:100%; margin-bottom:30px;">
                        <h2>Thêm sản phẩm mới</h2>

                        <form action="actions.php" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="action" value="save">

                            <div class="form-group">
                                <label>Tên sản phẩm</label>
                                <input type="text" name="name" required>
                            </div>

                            <div class="form-group">
                                <label>Mã SKU</label>
                                <input type="text" name="sku">
                            </div>

                            <div class="form-group">
                                <label>Mô tả sản phẩm</label>
                                <textarea name="description"></textarea>
                            </div>

                            <div class="form-group">
                                <label>Giá bán</label>
                                <input type="number" name="price" min="0" step="1000" required>
                            </div>

                            <div class="form-group">
                                <label>Giá khuyến mãi (có thể bỏ trống)</label>
                                <input type="number" name="sale_price" min="0" step="1000">
                            </div>



                            <div class="form-group">
                                <label>Các biến thể và tồn kho</label>
                                <p>Nhập từng tổ hợp màu và size. Ví dụ: Đen - XL - 20 sản phẩm.</p>

                                <div id="variant-list">
                                    <div class="variant-row">
                                        <input type="text" name="variant_color[]" placeholder="Màu">
                                        <input type="text" name="variant_size[]" placeholder="Size">
                                        <input type="number" name="variant_stock[]" placeholder="Tồn kho" min="0" value="0">
                                        <input type="text" name="variant_sku[]" placeholder="SKU biến thể">
                                        <button type="button" onclick="this.parentElement.remove()">Xóa</button>
                                    </div>
                                </div>

                                <button type="button" class="btn btn-yellow" onclick="addVariantRow()">
                                    + Thêm biến thể
                                </button>
                            </div>

                            <div class="form-group">
                                <label>Tồn kho chung (dùng khi không tạo biến thể)</label>
                                <input type="number" name="stock" min="0" value="0">
                            </div>

                            <div class="form-group">
                                <label>Ảnh sản phẩm (có thể chọn nhiều ảnh)</label>
                                <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
                            </div>

                            <button class="btn" type="submit">Lưu sản phẩm</button>
                        </form>
                    </div>
                <?php elseif ($page === 'products' && admin()): ?>
                    <h2>Danh sách sản phẩm</h2>
                    <form method="get" action="index.php" style="margin-bottom:20px;">

                        <input type="hidden" name="page" value="products">

                        <div style="display:flex;gap:15px;flex-wrap:wrap;margin-top:30px;margin-bottom:25px;align-items:end;">

                            <select name="stock" onchange="this.form.submit()"
                                style="max-width:220px;">

                                <option value="all" <?= $stockFilter === 'all' ? 'selected' : '' ?>>
                                    Tất cả sản phẩm
                                </option>

                                <option value="in_stock" <?= $stockFilter === 'in_stock' ? 'selected' : '' ?>>
                                    Còn hàng
                                </option>

                                <option value="low_stock" <?= $stockFilter === 'low_stock' ? 'selected' : '' ?>>
                                    Sắp hết hàng (1–5)
                                </option>

                                <option value="out_stock" <?= $stockFilter === 'out_stock' ? 'selected' : '' ?>>
                                    Hết hàng
                                </option>

                            </select>

                            <a class="btn btn-yellow"
                                href="export_products.php?stock=<?= e($stockFilter) ?>">
                                📥 Xuất Excel
                            </a>

                        </div>

                    </form>
                    <?php
                    $productStockStats = db()->query("
    SELECT
        COUNT(*) AS total_products,
        SUM(CASE WHEN stock BETWEEN 1 AND 5 THEN 1 ELSE 0 END) AS low_stock_products,
        SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock_products
    FROM products
")->fetch(PDO::FETCH_ASSOC);

                    $totalProducts = (int)$productStockStats['total_products'];
                    $lowStockProducts = (int)$productStockStats['low_stock_products'];
                    $outOfStockProducts = (int)$productStockStats['out_of_stock_products'];
                    ?>

                    <div class="customer-stats">

                        <div class="customer-stat-card">
                            <div class="customer-stat-icon">📦</div>
                            <div>
                                <p>Tổng sản phẩm</p>
                                <h2><?= number_format($totalProducts) ?></h2>
                            </div>
                        </div>

                        <div class="customer-stat-card">
                            <div class="customer-stat-icon">⚠️</div>
                            <div>
                                <p>Sản phẩm sắp hết</p>
                                <h2><?= number_format($lowStockProducts) ?></h2>
                            </div>
                        </div>

                        <div class="customer-stat-card">
                            <div class="customer-stat-icon">❌</div>
                            <div>
                                <p>Sản phẩm hết hàng</p>
                                <h2><?= number_format($outOfStockProducts) ?></h2>
                            </div>
                        </div>

                    </div>

                    <div class="table-wrap" style="margin-top:25px;overflow-x:auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Sản phẩm</th>
                                    <th>SKU</th>
                                    <th>Màu sắc</th>
                                    <th>Size</th>
                                    <th>Số lượng nhập</th>
                                    <th>Ghi chú</th>
                                    <th>Thời gian nhập</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adminProducts as $p): ?>
                                    <tr>
                                        <td><?= (int)$p['id'] ?></td>
                                        <td>
                                            <img
                                                src="<?= e(productImage($p)) ?>"
                                                width="55"
                                                height="55"
                                                style="object-fit:cover"
                                                alt="">
                                        </td>
                                        <td><?= e($p['name']) ?></td>
                                        <td><?= e($p['sku'] ?? '') ?></td>
                                        <td><?= money(displayPrice($p)) ?></td>

                                        <td>
                                            <?php if ((int)$p['stock'] <= 0): ?>

                                                <span style="color:#dc3545;font-weight:bold;">
                                                    Hết hàng (0)
                                                </span>

                                            <?php elseif ((int)$p['stock'] <= 5): ?>

                                                <span style="color:#e67e22;font-weight:bold;">
                                                    Sắp hết (<?= (int)$p['stock'] ?>)
                                                </span>

                                            <?php else: ?>

                                                <span style="color:#198754;font-weight:bold;">
                                                    Còn hàng (<?= (int)$p['stock'] ?>)
                                                </span>

                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= (int)$p['is_active'] === 1 ? 'Đang bán' : 'Đã ẩn' ?>
                                        </td>

                                        <td>
                                            <a class="btn btn-yellow"
                                                href="index.php?page=edit_product&id=<?= (int)$p['id'] ?>">
                                                Sửa
                                            </a>

                                            <form action="actions.php" method="post" style="margin-top:8px;">
                                                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                                <input type="hidden" name="action" value="toggle_product">
                                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                                <input type="hidden" name="new_status"
                                                    value="<?= (int)$p['is_active'] === 1 ? 0 : 1 ?>">

                                                <button class="btn" type="submit">
                                                    <?= (int)$p['is_active'] === 1 ? 'Ẩn sản phẩm' : 'Hiện sản phẩm' ?>
                                                </button>
                                            </form>
                                        </td>

                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($productTotalPages > 1): ?>

                        <div style="display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;margin:25px 0;">

                            <?php if ($productCurrentPage > 1): ?>
                                <a class="btn"
                                    href="index.php?page=products&stock=<?= e($stockFilter) ?>&p=<?= $productCurrentPage - 1 ?>">
                                    ← Trước
                                </a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $productTotalPages; $i++): ?>

                                <a class="btn"
                                    href="index.php?page=products&stock=<?= e($stockFilter) ?>&p=<?= $i ?>"
                                    style="<?= $i === $productCurrentPage ? 'background:#111;color:white;' : '' ?>">
                                    <?= $i ?>
                                </a>

                            <?php endfor; ?>

                            <?php if ($productCurrentPage < $productTotalPages): ?>
                                <a class="btn"
                                    href="index.php?page=products&stock=<?= e($stockFilter) ?>&p=<?= $productCurrentPage + 1 ?>">
                                    Sau →
                                </a>
                            <?php endif; ?>

                        </div>

                        <p style="text-align:center;color:#666;">
                            Trang <?= $productCurrentPage ?> / <?= $productTotalPages ?>
                            — Tổng <?= number_format($totalProductRows) ?> sản phẩm
                        </p>

                    <?php endif; ?>

                <?php elseif ($page === 'stock_import' && admin()): ?>

                    <h1 class="title">Nhập kho sản phẩm</h1>

                    <div class="form-box">

                        <form action="actions.php" method="post">

                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">

                            <input type="hidden" name="action" value="import_stock">

                            <div class="form-group">
                                <label>Chọn biến thể sản phẩm</label>

                                <select name="variant_id" required>
                                    <option value="">-- Chọn màu sắc / kích thước --</option>

                                    <?php foreach ($importVariants as $variant): ?>

                                        <option value="<?= (int)$variant['id'] ?>">
                                            <?= e($variant['product_name']) ?>
                                            - <?= e($variant['color']) ?>
                                            - Size <?= e($variant['size']) ?>
                                            (Tồn: <?= (int)$variant['stock'] ?>)
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                            <div class="form-group">
                                <label>Số lượng nhập</label>

                                <input
                                    type="number"
                                    name="quantity"
                                    min="1"
                                    required
                                    placeholder="Nhập số lượng hàng">
                            </div>

                            <div class="form-group">
                                <label>Ghi chú</label>

                                <textarea
                                    name="note"
                                    rows="4"
                                    placeholder="Ví dụ: Nhập hàng từ nhà cung cấp..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-yellow">
                                Xác nhận nhập kho
                            </button>

                            <a href="index.php?page=products" class="btn">
                                Quay lại
                            </a>

                        </form>

                    </div>
                <?php elseif ($page === 'stock_history' && admin()): ?>

                    <h1 class="title" style="margin-bottom:25px;">
                        Lịch sử nhập kho
                    </h1>
                    <div style="height:20px;"></div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;margin-bottom:30px;">
                        <div class="form-box" style="margin:0;padding:20px;">
                            <div style="color:#777;font-size:14px;">Tổng số lần nhập</div>
                            <h2 style="color:#2563eb;font-size:28px;margin:10px 0 0;">
                                <?= number_format($historyTotal) ?>
                            </h2>
                        </div>

                        <div class="form-box" style="margin:0;padding:20px;">
                            <div style="color:#777;font-size:14px;">Tổng số lượng đã nhập</div>
                            <h2 style="color:#198754;font-size:28px;margin:10px 0 0;">
                                <?= number_format($historyTotalQuantity) ?>
                            </h2>
                        </div>

                    </div>

                    <h2 style="margin:30px 0 20px;">
                        Thống kê nhập kho theo sản phẩm
                    </h2>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Sản phẩm</th>
                                    <th>Số lần nhập</th>
                                    <th>Tổng số lượng nhập</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($historyByProduct)): ?>
                                    <tr>
                                        <td colspan="4" style="text-align:center;">
                                            Chưa có dữ liệu thống kê.
                                        </td>
                                    </tr>
                                <?php else: ?>

                                    <?php foreach ($historyByProduct as $i => $product): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= e($product['product_name']) ?></td>
                                            <td><?= number_format($product['import_count']) ?></td>
                                            <td>
                                                <strong style="color:#198754;">
                                                    <?= number_format($product['total_quantity']) ?>
                                                </strong>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>


                    <form method="get" action="index.php"
                        style="display:flex;gap:15px;flex-wrap:wrap;margin-top:30px;margin-bottom:25px;align-items:end;">

                        <input type="hidden" name="page" value="stock_history">

                        <div>
                            <label>Tìm kiếm sản phẩm</label>
                            <input
                                type="text"
                                name="q"
                                placeholder="Tên, SKU, màu sắc, size..."
                                value="<?= e($historySearch) ?>">
                        </div>
                        <div style="margin-bottom:15px;">
                            Tìm thấy <strong><?= $historyTotal ?></strong> lần nhập kho.
                            <span style="margin-left:10px;">
                                Trang <?= $historyPage ?> / <?= $historyTotalPages ?>
                            </span>
                        </div>
                        <div>
                            <label>Từ ngày</label>
                            <input
                                type="date"
                                name="from"
                                value="<?= e($historyFrom) ?>">
                        </div>

                        <div>
                            <label>Đến ngày</label>
                            <input
                                type="date"
                                name="to"
                                value="<?= e($historyTo) ?>">
                        </div>

                        <button type="submit" class="btn btn-yellow">
                            🔍 Tìm kiếm
                        </button>

                        <a href="index.php?page=stock_history" class="btn">
                            Xóa bộ lọc
                        </a>
                        <a class="btn btn-yellow"
                            href="export_stock_history.php?q=<?= urlencode($historySearch) ?>&from=<?= urlencode($historyFrom) ?>&to=<?= urlencode($historyTo) ?>">
                            📥 Xuất Excel
                        </a>
                    </form>

                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin:25px 0;align-items:center;">
                        <a href="index.php?page=stock_import" class="btn btn-yellow">
                            📥 Nhập kho mới
                        </a>

                        <a href="index.php?page=products" class="btn">
                            Quay lại sản phẩm
                        </a>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Sản phẩm</th>
                                    <th>SKU</th>
                                    <th>Màu sắc</th>
                                    <th>Size</th>
                                    <th>Số lượng nhập</th>
                                    <th>Ghi chú</th>
                                    <th>Thời gian nhập</th>
                                    <th>Thao tác</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (empty($stockImportHistory)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align:center;">
                                            Chưa có lịch sử nhập kho.
                                        </td>
                                    </tr>
                                <?php else: ?>

                                    <?php foreach ($stockImportHistory as $i => $item): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td><?= e($item['product_name']) ?></td>
                                            <td><?= e($item['sku'] ?? '') ?></td>
                                            <td><?= e($item['color'] ?? '') ?></td>
                                            <td><?= e($item['size'] ?? '') ?></td>
                                            <td>
                                                <strong style="color:#198754;">
                                                    +<?= (int)$item['quantity'] ?>
                                                </strong>
                                            </td>
                                            <td><?= e($item['note'] ?? '') ?></td>
                                            <td>
                                                <?= date('d/m/Y H:i', strtotime($item['created_at'])) ?>
                                            </td>

                                            <td style="white-space:nowrap;">
                                                <div style="display:flex;gap:8px;align-items:center;">

                                                    <a class="btn"
                                                        href="index.php?page=stock_import_detail&id=<?= (int)$item['id'] ?>">
                                                        Xem chi tiết
                                                    </a>

                                                    <form action="actions.php" method="post"
                                                        style="margin:0;"
                                                        onsubmit="return confirm('Bạn có chắc muốn xóa lịch sử nhập kho này? Tồn kho sẽ không bị thay đổi.');">

                                                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                                        <input type="hidden" name="action" value="delete_stock_import">
                                                        <input type="hidden" name="import_id" value="<?= (int)$item['id'] ?>">

                                                        <button type="submit" class="btn"
                                                            style="background:#dc3545;color:white;">
                                                            Xóa
                                                        </button>

                                                    </form>

                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>


                    <?php if ($historyTotalPages > 1): ?>

                        <div style="display:flex;gap:8px;justify-content:center;margin:20px 0;flex-wrap:wrap;">

                            <?php if ($historyPage > 1): ?>
                                <a class="btn"
                                    href="index.php?page=stock_history&q=<?= urlencode($historySearch) ?>&from=<?= urlencode($historyFrom) ?>&to=<?= urlencode($historyTo) ?>&p=<?= $historyPage - 1 ?>">
                                    ← Trước
                                </a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $historyTotalPages; $i++): ?>

                                <a class="btn <?= $i === $historyPage ? 'btn-yellow' : '' ?>"
                                    href="index.php?page=stock_history&q=<?= urlencode($historySearch) ?>&from=<?= urlencode($historyFrom) ?>&to=<?= urlencode($historyTo) ?>&p=<?= $i ?>">
                                    <?= $i ?>
                                </a>

                            <?php endfor; ?>

                            <?php if ($historyPage < $historyTotalPages): ?>
                                <a class="btn"
                                    href="index.php?page=stock_history&q=<?= urlencode($historySearch) ?>&from=<?= urlencode($historyFrom) ?>&to=<?= urlencode($historyTo) ?>&p=<?= $historyPage + 1 ?>">
                                    Tiếp →
                                </a>
                            <?php endif; ?>

                        </div>

                    <?php endif; ?>
                <?php elseif ($page === 'stock_import_detail' && admin()): ?>

                    <h1 class="title">Chi tiết lần nhập kho</h1>

                    <?php if (!$stockImportDetail): ?>

                        <p>Không tìm thấy lịch sử nhập kho.</p>

                    <?php else: ?>

                        <div class="form-box">

                            <p><strong>Mã nhập kho:</strong>
                                #<?= (int)$stockImportDetail['id'] ?>
                            </p>

                            <p><strong>Sản phẩm:</strong>
                                <?= e($stockImportDetail['product_name']) ?>
                            </p>

                            <p><strong>SKU:</strong>
                                <?= e($stockImportDetail['sku'] ?? '') ?>
                            </p>

                            <p><strong>Màu sắc:</strong>
                                <?= e($stockImportDetail['color'] ?? '') ?>
                            </p>

                            <p><strong>Size:</strong>
                                <?= e($stockImportDetail['size'] ?? '') ?>
                            </p>

                            <p><strong>Số lượng nhập:</strong>
                                <span style="color:#198754;font-weight:bold;">
                                    +<?= (int)$stockImportDetail['quantity'] ?>
                                </span>
                            </p>

                            <p><strong>Ghi chú:</strong>
                                <?= e($stockImportDetail['note'] ?? '') ?: 'Không có' ?>
                            </p>

                            <p><strong>Thời gian nhập:</strong>
                                <?= date(
                                    'd/m/Y H:i:s',
                                    strtotime($stockImportDetail['created_at'])
                                ) ?>
                            </p>

                            <a href="index.php?page=stock_history" class="btn btn-yellow">
                                Quay lại lịch sử nhập kho
                            </a>

                        </div>

                    <?php endif; ?>
                <?php elseif ($page === 'stock_management' && admin()): ?>

                    <h1 class="title">Quản lý tồn kho</h1>

                    <!-- Thống kê tồn kho -->
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:30px;">

                        <div class="form-box">
                            <div style="color:#777;">Tổng số lượng tồn kho</div>
                            <h2 style="color:#2563eb;margin-top:10px;">
                                <?= number_format((int)$stockManagementStats['total_stock']) ?>
                            </h2>
                        </div>

                        <div class="form-box">
                            <div style="color:#777;">Biến thể sắp hết hàng</div>
                            <h2 style="color:#e67e22;margin-top:10px;">
                                <?= number_format((int)$stockManagementStats['low_stock']) ?>
                            </h2>
                        </div>

                        <div class="form-box">
                            <div style="color:#777;">Biến thể hết hàng</div>
                            <h2 style="color:#dc3545;margin-top:10px;">
                                <?= number_format((int)$stockManagementStats['out_stock']) ?>
                            </h2>
                        </div>

                    </div>

                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:25px;">

                        <a href="index.php?page=stock_import" class="btn btn-yellow">
                            📥 Nhập kho mới
                        </a>

                        <a href="index.php?page=stock_history" class="btn">
                            📋 Lịch sử nhập kho
                        </a>

                    </div>

                    <h2 style="margin-bottom:20px;">Tồn kho theo màu sắc và size</h2>

                    <form method="get" action="index.php"
                        style="display:flex;gap:15px;flex-wrap:wrap;align-items:end;margin-bottom:25px;">

                        <input type="hidden" name="page" value="stock_management">

                        <div>
                            <label>Tìm kiếm</label>
                            <input
                                type="text"
                                name="q"
                                placeholder="Tên sản phẩm, SKU, màu, size..."
                                value="<?= e($stockSearch) ?>">
                        </div>

                        <div>
                            <label>Trạng thái tồn kho</label>
                            <select name="status">
                                <option value="all" <?= $stockStatusFilter === 'all' ? 'selected' : '' ?>>
                                    Tất cả
                                </option>

                                <option value="in_stock" <?= $stockStatusFilter === 'in_stock' ? 'selected' : '' ?>>
                                    Còn hàng
                                </option>

                                <option value="low_stock" <?= $stockStatusFilter === 'low_stock' ? 'selected' : '' ?>>
                                    Sắp hết (1–5)
                                </option>

                                <option value="out_stock" <?= $stockStatusFilter === 'out_stock' ? 'selected' : '' ?>>
                                    Hết hàng (0)
                                </option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-yellow">
                            🔍 Tìm kiếm
                        </button>

                        <a href="index.php?page=stock_management" class="btn">
                            Xóa bộ lọc
                        </a>

                        <a class="btn btn-yellow"
                            href="export_stock_management.php?q=<?= urlencode($stockSearch) ?>&status=<?= urlencode($stockStatusFilter) ?>">
                            📥 Xuất Excel
                        </a>

                    </form>
                    <div class="table-wrap" style="overflow-x:auto;">

                        <table style="width:100%;min-width:850px;">

                            <thead>
                                <tr>
                                    <th>STT</th>
                                    <th>Sản phẩm</th>
                                    <th>SKU</th>
                                    <th>Màu sắc</th>
                                    <th>Size</th>
                                    <th>Số lượng tồn</th>
                                    <th>Trạng thái</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (empty($stockManagementVariants)): ?>

                                    <tr>
                                        <td colspan="7" style="text-align:center;">
                                            Chưa có dữ liệu tồn kho.
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($stockManagementVariants as $i => $variant): ?>

                                        <tr>
                                            <td><?= $i + 1 ?></td>

                                            <td>
                                                <?= e($variant['product_name']) ?>
                                            </td>

                                            <td>
                                                <?= e($variant['sku'] ?? '') ?>
                                            </td>

                                            <td>
                                                <?= e($variant['color'] ?? '') ?>
                                            </td>

                                            <td>
                                                <?= e($variant['size'] ?? '') ?>
                                            </td>

                                            <td>
                                                <strong>
                                                    <?= number_format((int)$variant['stock']) ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <?php if ((int)$variant['stock'] <= 0): ?>

                                                    <span style="color:#dc3545;font-weight:bold;">
                                                        Hết hàng
                                                    </span>

                                                <?php elseif ((int)$variant['stock'] <= 5): ?>

                                                    <span style="color:#e67e22;font-weight:bold;">
                                                        Sắp hết
                                                    </span>

                                                <?php else: ?>

                                                    <span style="color:#198754;font-weight:bold;">
                                                        Còn hàng
                                                    </span>

                                                <?php endif; ?>
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>
                    <!-- Thông tin phân trang -->
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-top:20px;">

                        <div>
                            Tìm thấy
                            <strong><?= number_format($stockManagementTotal) ?></strong>
                            biến thể tồn kho.

                            Trang
                            <strong><?= $stockManagementPage ?></strong>
                            /
                            <?= $stockManagementTotalPages ?>
                        </div>

                        <div style="display:flex;gap:8px;align-items:center;">

                            <?php if ($stockManagementPage > 1): ?>
                                <a class="btn"
                                    href="index.php?page=stock_management&q=<?= urlencode($stockSearch) ?>&status=<?= urlencode($stockStatusFilter) ?>&p=<?= $stockManagementPage - 1 ?>">
                                    ← Trang trước
                                </a>
                            <?php endif; ?>

                            <?php if ($stockManagementPage < $stockManagementTotalPages): ?>
                                <a class="btn btn-yellow"
                                    href="index.php?page=stock_management&q=<?= urlencode($stockSearch) ?>&status=<?= urlencode($stockStatusFilter) ?>&p=<?= $stockManagementPage + 1 ?>">
                                    Trang sau →
                                </a>
                            <?php endif; ?>

                        </div>

                    <?php elseif ($page === 'stock_movements'): ?>

                        <div style="
    display:flex;
    justify-content:space-between;
    align-items:center;
    flex-wrap:wrap;
    gap:12px;
    margin-bottom:15px;
">
                            <h1 style="margin:0;">Lịch sử biến động kho</h1>

                            <a href="export_stock_movements.php"
                                class="btn"
                                style="background:#198754;color:white;text-decoration:none;">
                                📥 Xuất Excel
                            </a>
                        </div>
                        <p style="color:#666;margin-bottom:20px;">
                            Theo dõi các lần nhập kho, bán hàng, hủy đơn và điều chỉnh tồn kho.
                        </p>

                        <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>STT</th>
                                        <th>Sản phẩm</th>
                                        <th>SKU</th>
                                        <th>Màu sắc</th>
                                        <th>Size</th>
                                        <th>Loại biến động</th>
                                        <th>Số lượng thay đổi</th>
                                        <th>Mã tham chiếu</th>
                                        <th>Ghi chú</th>
                                        <th>Thời gian</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if (empty($stockMovements)): ?>
                                        <tr>
                                            <td colspan="10" style="text-align:center;padding:25px;">
                                                Chưa có lịch sử biến động kho.
                                            </td>
                                        </tr>
                                    <?php else: ?>

                                        <?php foreach ($stockMovements as $index => $movement): ?>

                                            <?php
                                            $movementLabels = [
                                                'import' => 'Nhập kho',
                                                'sale' => 'Bán hàng',
                                                'cancel' => 'Hủy đơn',
                                                'return' => 'Hoàn hàng',
                                                'adjustment' => 'Điều chỉnh kho'
                                            ];

                                            $typeLabel = $movementLabels[$movement['movement_type']]
                                                ?? $movement['movement_type'];

                                            $quantityChange = (int)$movement['quantity_change'];
                                            ?>

                                            <tr>
                                                <td><?= $index + 1 ?></td>

                                                <td><?= e($movement['product_name']) ?></td>

                                                <td><?= e($movement['sku'] ?? '') ?></td>

                                                <td><?= e($movement['color'] ?? '') ?></td>

                                                <td><?= e($movement['size'] ?? '') ?></td>

                                                <td><?= e($typeLabel) ?></td>

                                                <td style="
                                font-weight:bold;
                                color:<?= $quantityChange >= 0 ? '#198754' : '#dc3545' ?>;
                            ">
                                                    <?= $quantityChange > 0 ? '+' : '' ?><?= $quantityChange ?>
                                                </td>

                                                <td><?= e($movement['reference_code'] ?? '') ?></td>

                                                <td><?= e($movement['note'] ?? '') ?></td>

                                                <td>
                                                    <?= date('d/m/Y H:i', strtotime($movement['created_at'])) ?>
                                                </td>
                                            </tr>

                                        <?php endforeach; ?>

                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif ($page === 'stock_adjustment' && admin()): ?>

                        <h1 class="title">Điều chỉnh tồn kho</h1>

                        <p style="color:#666;margin-bottom:20px;">
                            Tăng hoặc giảm số lượng tồn kho và lưu lại lịch sử điều chỉnh.
                        </p>

                        <?php if (empty($stockAdjustmentVariants)): ?>

                            <div class="message">
                                Chưa có biến thể sản phẩm để điều chỉnh tồn kho.
                            </div>

                        <?php else: ?>

                            <div class="form-box">

                                <form action="actions.php" method="post">

                                    <input type="hidden" name="csrf"
                                        value="<?= e(csrf()) ?>">

                                    <input type="hidden" name="action"
                                        value="adjust_stock">

                                    <div class="form-group">
                                        <label>Chọn sản phẩm và phân loại</label>

                                        <select name="variant_id" required>
                                            <option value="">-- Chọn sản phẩm --</option>

                                            <?php foreach ($stockAdjustmentVariants as $variant): ?>

                                                <option value="<?= (int)$variant['variant_id'] ?>">
                                                    <?= e($variant['product_name']) ?>
                                                    - <?= e($variant['color']) ?>
                                                    - Size <?= e($variant['size']) ?>
                                                    - Tồn: <?= (int)$variant['stock'] ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Loại điều chỉnh</label>

                                        <select name="adjustment_type" required>
                                            <option value="increase">Tăng tồn kho</option>
                                            <option value="decrease">Giảm tồn kho</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Số lượng điều chỉnh</label>

                                        <input type="number"
                                            name="quantity"
                                            min="1"
                                            step="1"
                                            required
                                            placeholder="Nhập số lượng">
                                    </div>

                                    <div class="form-group">
                                        <label>Lý do điều chỉnh</label>

                                        <textarea name="note"
                                            rows="3"
                                            required
                                            placeholder="Ví dụ: Kiểm kê thực tế, hàng lỗi, thất thoát..."></textarea>
                                    </div>

                                    <button type="submit" class="btn">
                                        Xác nhận điều chỉnh
                                    </button>

                                </form>

                            </div>

                        <?php endif; ?>
                    <?php elseif ($page === 'edit_product' && admin()): ?>

                        <?php
                        $editColors = json_decode($editProduct['colors'] ?? '[]', true);
                        if (!is_array($editColors)) {
                            $editColors = [];
                        }

                        $editSizes = json_decode($editProduct['sizes'] ?? '[]', true);
                        if (!is_array($editSizes)) {
                            $editSizes = [];
                        }
                        ?>

                        <?php if (!$editProduct): ?>

                            <div class="message">
                                Không tìm thấy sản phẩm.
                            </div>

                            <a href="index.php?page=admin" class="btn">
                                Quay lại quản trị
                            </a>

                        <?php else: ?>

                            <h1 class="title">Sửa sản phẩm</h1>

                            <div class="form-box">

                                <form action="actions.php" method="post"
                                    enctype="multipart/form-data">

                                    <input type="hidden" name="csrf"
                                        value="<?= e(csrf()) ?>">

                                    <input type="hidden" name="action" value="save">

                                    <input type="hidden" name="id"
                                        value="<?= (int)$editProduct['id'] ?>">

                                    <div class="form-group">
                                        <label>Tên sản phẩm</label>
                                        <input type="text" name="name" required
                                            value="<?= e($editProduct['name']) ?>">
                                    </div>

                                    <div class="form-group">
                                        <label>Mã SKU</label>
                                        <input type="text" name="sku"
                                            value="<?= e($editProduct['sku'] ?? '') ?>">
                                    </div>

                                    <div class="form-group">
                                        <label>Giá bán</label>
                                        <input type="number" name="price" min="1"
                                            step="any" required
                                            value="<?= e($editProduct['price']) ?>">
                                    </div>

                                    <div class="form-group">
                                        <label>Giá khuyến mãi</label>
                                        <input type="number" name="sale_price"
                                            min="0" step="any"
                                            value="<?= e($editProduct['sale_price'] ?? '') ?>">
                                    </div>

                                    <div class="form-group">
                                        <label>Tồn kho tổng</label>
                                        <input type="number" name="stock" min="0"
                                            value="<?= (int)$editProduct['stock'] ?>">
                                    </div>



                                    <div class="form-group">
                                        <label>Mô tả sản phẩm</label>
                                        <textarea name="description" rows="5"><?= e($editProduct['description'] ?? '') ?></textarea>
                                    </div>

                                    <h3>Biến thể màu sắc / kích thước</h3>

                                    <div id="variant-list">

                                        <?php if (!empty($editVariants)): ?>

                                            <?php foreach ($editVariants as $variant): ?>

                                                <div class="variant-row">

                                                    <input type="text"
                                                        name="variant_color[]"
                                                        placeholder="Màu sắc"
                                                        value="<?= e($variant['color']) ?>">

                                                    <input type="text"
                                                        name="variant_size[]"
                                                        placeholder="Kích thước"
                                                        value="<?= e($variant['size']) ?>">

                                                    <input type="number"
                                                        name="variant_stock[]"
                                                        placeholder="Tồn kho"
                                                        min="0"
                                                        value="<?= (int)$variant['stock'] ?>">

                                                    <input type="text"
                                                        name="variant_sku[]"
                                                        placeholder="SKU biến thể"
                                                        value="<?= e($variant['sku'] ?? '') ?>">

                                                    <button
                                                        type="button"
                                                        onclick="this.parentElement.remove()"
                                                        style="background:#dc3545;color:white;border:0;padding:8px 12px;border-radius:5px;cursor:pointer;">
                                                        Xóa
                                                    </button>

                                                </div>

                                            <?php endforeach; ?>

                                        <?php else: ?>

                                            <div class="variant-row">

                                                <input type="text" name="variant_color[]"
                                                    placeholder="Màu sắc">

                                                <input type="text" name="variant_size[]"
                                                    placeholder="Kích thước">

                                                <input type="number" name="variant_stock[]"
                                                    placeholder="Tồn kho" min="0" value="0">

                                                <input type="text" name="variant_sku[]"
                                                    placeholder="SKU biến thể">

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                    <button type="button" class="btn"
                                        onclick="addVariantRow()">
                                        + Thêm biến thể
                                    </button>

                                    <div class="form-group" style="margin-top:20px;">
                                        <label>Thêm ảnh mới (không chọn nếu giữ ảnh cũ)</label>
                                        <input type="file" name="images[]" multiple
                                            accept="image/*">
                                        <?php if (!empty($editImages)): ?>

                                            <div class="form-group">
                                                <label>Ảnh sản phẩm hiện tại</label>

                                                <div style="display:flex; flex-wrap:wrap; gap:12px;">

                                                    <?php foreach ($editImages as $image): ?>
                                                        <div style="width:120px;">
                                                            <img
                                                                src="<?= e($image['path']) ?>"
                                                                alt="Ảnh sản phẩm"
                                                                style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ddd;">

                                                            <small>Ảnh #<?= (int)$image['id'] ?></small>

                                                            <button
                                                                type="submit"
                                                                name="action"
                                                                value="delete_product_image"
                                                                formaction="actions.php?image_id=<?= (int)$image['id'] ?>&product_id=<?= (int)$editProduct['id'] ?>"
                                                                formmethod="post"
                                                                formnovalidate
                                                                onclick="return confirm('Cậu có chắc muốn xóa ảnh này không?')"
                                                                style="width:100%;margin-top:6px;padding:8px;background:#dc3545;color:white;border:0;border-radius:5px;cursor:pointer;">
                                                                Xóa ảnh
                                                            </button>
                                                        </div>
                                                    <?php endforeach; ?>

                                                </div>
                                            </div>

                                        <?php else: ?>

                                            <p>Chưa có ảnh trong danh sách ảnh sản phẩm.</p>

                                        <?php endif; ?>
                                    </div>

                                    <div style="margin-top:20px;">
                                        <button type="submit" class="btn btn-yellow">
                                            Lưu thay đổi
                                        </button>

                                        <a href="index.php?page=admin" class="btn">
                                            Quay lại
                                        </a>
                                    </div>

                                </form>

                            </div>

                        <?php endif; ?>
                    <?php elseif ($page === 'orders' && admin()): ?>

                        <h1 class="title">Quản lý đơn hàng</h1>

                        <form method="get" action="index.php" style="margin-bottom:20px;">
                            <input type="hidden" name="page" value="orders">
                            <input type="hidden" name="per_page" value="10">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">

                                <input
                                    type="text"
                                    name="q"
                                    placeholder="Tìm mã đơn, tên khách hoặc số điện thoại..."
                                    value="<?= e($_GET['q'] ?? '') ?>"
                                    style="width:auto;flex:1;min-width:220px;">

                                <select name="status" onchange="this.form.submit()">
                                    <option value="">Tất cả trạng thái</option>

                                    <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>
                                        Chờ xác nhận
                                    </option>

                                    <option value="confirmed" <?= ($_GET['status'] ?? '') === 'confirmed' ? 'selected' : '' ?>>
                                        Đã xác nhận
                                    </option>

                                    <option value="shipping" <?= ($_GET['status'] ?? '') === 'shipping' ? 'selected' : '' ?>>
                                        Đang giao
                                    </option>

                                    <option value="failed_delivery" <?= ($_GET['status'] ?? '') === 'failed_delivery' ? 'selected' : '' ?>>
                                        Giao hàng thất bại
                                    </option>

                                    <option value="returned" <?= ($_GET['status'] ?? '') === 'returned' ? 'selected' : '' ?>>
                                        Đã hoàn hàng
                                    </option>

                                    <option value="completed" <?= ($_GET['status'] ?? '') === 'completed' ? 'selected' : '' ?>>
                                        Hoàn thành
                                    </option>

                                    <option value="cancelled" <?= ($_GET['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>
                                        Đã hủy
                                    </option>
                                </select>

                                <button class="btn" type="submit">
                                    Lọc / Tìm kiếm
                                </button>

                                <a class="btn" href="index.php?page=orders">
                                    Xóa lọc
                                </a>
                                <a class="btn"
                                    href="export_orders.php?q=<?= urlencode($keyword) ?>&status=<?= urlencode($orderStatus) ?>"
                                    style="background:#198754;color:white;">
                                    📥 Xuất Excel
                                </a>
                            </div>

                        </form>

                        <div class="table-wrap" style="overflow-x:auto;">
                            <table style="width:100%;min-width:1000px;border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th>Mã đơn</th>
                                        <th>Khách hàng</th>
                                        <th>Điện thoại</th>
                                        <th>Tổng tiền</th>
                                        <th>Thanh toán</th>
                                        <th>Trạng thái</th>
                                        <th>Chi tiết</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $o): ?>
                                        <tr>
                                            <td><?= e($o['code']) ?></td>
                                            <td><?= e($o['customer_name']) ?></td>
                                            <td><?= e($o['phone']) ?></td>
                                            <td><?= money($o['total']) ?></td>
                                            <td><?= e($o['payment_method']) ?></td>
                                            <td>
                                                <form action="actions.php" method="post">
                                                    <input type="hidden" name="csrf"
                                                        value="<?= e(csrf()) ?>">
                                                    <input type="hidden" name="action"
                                                        value="order_status">
                                                    <input type="hidden" name="order_id"
                                                        value="<?= (int)$o['id'] ?>">

                                                    <select name="status">
                                                        <?php
                                                        $statuses = [
                                                            'pending' => 'Chờ xác nhận',
                                                            'confirmed' => 'Đã xác nhận',
                                                            'shipping' => 'Đang giao',
                                                            'failed_delivery' => 'Giao hàng thất bại',
                                                            'returned' => 'Đã hoàn hàng',
                                                            'completed' => 'Hoàn thành',
                                                            'cancelled' => 'Đã hủy'
                                                        ];
                                                        ?>
                                                        <?php foreach ($statuses as $key => $label): ?>
                                                            <option value="<?= e($key) ?>"
                                                                <?= $o['status'] === $key ? 'selected' : '' ?>>
                                                                <?= e($label) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>

                                                    <button class="btn" type="submit"
                                                        style="margin-top:5px;">
                                                        Cập nhật
                                                    </button>
                                                </form>
                                            </td>
                                            <td>
                                                <a class="btn"
                                                    href="index.php?page=order_detail&id=<?= (int)$o['id'] ?>">
                                                    Xem
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($orders)): ?>
                                        <tr>
                                            <td colspan="7">Chưa có đơn hàng nào.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div style="display:flex;justify-content:center;align-items:center;gap:8px;flex-wrap:wrap;margin-top:20px;">

                                <?php if ($currentPage > 1): ?>
                                    <a class="btn"
                                        href="index.php?page=orders&q=<?= urlencode($keyword) ?>&status=<?= urlencode($orderStatus) ?>&per_page=<?= $perPage ?>&p=<?= $currentPage - 1 ?>">
                                        Trang trước
                                    </a>
                                <?php endif; ?>

                                <span>
                                    Trang <?= $currentPage ?> / <?= $totalPages ?>
                                    (Tổng <?= $totalOrders ?> đơn hàng)
                                </span>

                                <?php if ($currentPage < $totalPages): ?>
                                    <a class="btn"
                                        href="index.php?page=orders&q=<?= urlencode($keyword) ?>&status=<?= urlencode($orderStatus) ?>&per_page=<?= $perPage ?>&p=<?= $currentPage + 1 ?>">
                                        Trang sau
                                    </a>
                                <?php endif; ?>

                            </div>
                        <?php endif; ?>

                    <?php elseif ($page === 'order_detail' && admin()): ?>

                        <?php if (!$orderDetail): ?>

                            <div class="message">Không tìm thấy đơn hàng.</div>

                        <?php else: ?>

                            <h1 class="title">
                                Chi tiết đơn hàng <?= e($orderDetail['code']) ?>
                            </h1>

                            <div class="form-box" style="max-width:100%;margin-bottom:20px;">
                                <h3>Thông tin khách hàng</h3>
                                <p>Họ tên: <?= e($orderDetail['customer_name']) ?></p>
                                <p>Điện thoại: <?= e($orderDetail['phone']) ?></p>
                                <p>Email: <?= e($orderDetail['email'] ?? '') ?></p>
                                <p>Địa chỉ: <?= e($orderDetail['address']) ?></p>
                                <p>Ghi chú: <?= e($orderDetail['note'] ?? '') ?></p>
                                <p>Phương thức thanh toán: <?= e($orderDetail['payment_method']) ?></p>
                                <p>Trạng thái thanh toán: <?= e($orderDetail['payment_status']) ?></p>
                                <p>Trạng thái đơn: <?= e($orderDetail['status']) ?></p>
                            </div>

                            <h2>Sản phẩm trong đơn</h2>

                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Sản phẩm</th>
                                            <th>SKU</th>
                                            <th>Màu</th>
                                            <th>Size</th>
                                            <th>Đơn giá</th>
                                            <th>Số lượng</th>
                                            <th>Thành tiền</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($orderItems as $item): ?>
                                            <tr>
                                                <td><?= e($item['product_name']) ?></td>
                                                <td><?= e($item['sku'] ?? '') ?></td>
                                                <td><?= e($item['color'] ?? '') ?></td>
                                                <td><?= e($item['size'] ?? '') ?></td>
                                                <td><?= money($item['price']) ?></td>
                                                <td><?= (int)$item['quantity'] ?></td>
                                                <td>
                                                    <?= money($item['price'] * $item['quantity']) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div style="text-align:right;margin-top:20px;">
                                <p>Tạm tính: <?= money($orderDetail['subtotal']) ?></p>
                                <p>Phí vận chuyển: <?= money($orderDetail['shipping_fee']) ?></p>
                                <p>Giảm giá: <?= money($orderDetail['discount']) ?></p>
                                <h2>Tổng thanh toán: <?= money($orderDetail['total']) ?></h2>
                            </div>

                            <a class="btn" href="index.php?page=orders">Quay lại danh sách đơn</a>

                        <?php endif; ?>

                    <?php elseif ($page === 'customers' && admin()): ?>

                        <h1 class="title">Quản lý khách hàng</h1>
                        <form method="get" action="index.php" style="margin-bottom:20px;">
                            <input type="hidden" name="page" value="customers">

                            <input
                                type="text"
                                name="q"
                                placeholder="Tìm tên khách hoặc số điện thoại..."
                                value="<?= e($_GET['q'] ?? '') ?>"
                                style="max-width:400px;">

                            <button class="btn" type="submit">Tìm kiếm</button>

                            <a class="btn" href="index.php?page=customers">Xóa lọc</a>
                        </form>

                        <?php
                        $keyword = trim($_GET['q'] ?? '');
                        $search = '%' . $keyword . '%';

                        // Thống kê toàn bộ khách hàng
                        $stmt = db()->prepare("
    SELECT
        COUNT(DISTINCT phone) AS total_customers,
        COUNT(*) AS total_orders,
        SUM(
            CASE
                WHEN status = 'completed' THEN total
                ELSE 0
            END
        ) AS total_revenue
    FROM orders
    WHERE customer_name LIKE ?
       OR phone LIKE ?
");

                        $stmt->execute([$search, $search]);
                        $customerStats = $stmt->fetch();

                        $totalCustomers = (int)$customerStats['total_customers'];
                        $totalCustomerOrders = (int)$customerStats['total_orders'];
                        $totalCustomerRevenue = (float)$customerStats['total_revenue'];

                        // Phân trang
                        $perPage = 10;
                        $currentPage = max(1, (int)($_GET['p'] ?? 1));
                        $totalPages = max(1, (int)ceil($totalCustomers / $perPage));
                        $currentPage = min($currentPage, $totalPages);
                        $offset = ($currentPage - 1) * $perPage;

                        // Lấy khách hàng theo trang
                        $sql = "
    SELECT
        phone,
        MAX(customer_name) AS customer_name,
        MAX(email) AS email,
        COUNT(*) AS total_orders,
        SUM(
            CASE
                WHEN status = 'completed' THEN total
                ELSE 0
            END
        ) AS total_spent,
        MAX(created_at) AS last_order
    FROM orders
    WHERE customer_name LIKE ?
       OR phone LIKE ?
    GROUP BY phone
    ORDER BY last_order DESC
    LIMIT $perPage OFFSET $offset
";

                        $stmt = db()->prepare($sql);
                        $stmt->execute([$search, $search]);
                        $customers = $stmt->fetchAll();
                        ?>
                        <div class="customer-stats">

                            <div class="customer-stat-card">
                                <div class="customer-stat-icon">👥</div>
                                <div>
                                    <p>Tổng khách hàng</p>
                                    <h2><?= number_format($totalCustomers) ?></h2>
                                </div>
                            </div>

                            <div class="customer-stat-card">
                                <div class="customer-stat-icon">🛒</div>
                                <div>
                                    <p>Tổng đơn hàng</p>
                                    <h2><?= number_format($totalCustomerOrders) ?></h2>
                                </div>
                            </div>

                            <div class="customer-stat-card">
                                <div class="customer-stat-icon">💰</div>
                                <div>
                                    <p>Doanh thu hoàn thành</p>
                                    <h2><?= money($totalCustomerRevenue) ?></h2>
                                </div>
                            </div>

                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                    <tr>
                                        <th>STT</th>
                                        <th>Khách hàng</th>
                                        <th>Số điện thoại</th>
                                        <th>Email</th>
                                        <th>Số đơn hàng</th>
                                        <th>Tổng tiền đã mua</th>
                                        <th>Đơn gần nhất</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($customers as $i => $customer): ?>
                                        <tr>
                                            <td><?= $i + 1 ?></td>
                                            <td>
                                                <a href="index.php?page=customer_detail&phone=<?= urlencode($customer['phone']) ?>">
                                                    <?= e($customer['customer_name']) ?>
                                                </a>
                                            </td>
                                            <td><?= e($customer['phone']) ?></td>
                                            <td><?= e($customer['email'] ?? '') ?></td>
                                            <td><?= (int)$customer['total_orders'] ?></td>
                                            <td><?= money($customer['total_spent'] ?? 0) ?></td>
                                            <td>
                                                <?= !empty($customer['last_order'])
                                                    ? date('d/m/Y', strtotime($customer['last_order']))
                                                    : '' ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (empty($customers)): ?>
                                        <tr>
                                            <td colspan="7">Chưa có khách hàng nào.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($totalPages > 1): ?>

                            <div style="display:flex;justify-content:center;align-items:center;gap:8px;margin:25px 0;flex-wrap:wrap;">

                                <?php if ($currentPage > 1): ?>
                                    <a class="btn"
                                        href="index.php?page=customers&q=<?= urlencode($keyword) ?>&p=<?= $currentPage - 1 ?>">
                                        ← Trang trước
                                    </a>
                                <?php endif; ?>

                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>

                                    <a class="btn"
                                        href="index.php?page=customers&q=<?= urlencode($keyword) ?>&p=<?= $i ?>"
                                        style="<?= $i === $currentPage ? 'background:#222;color:#fff;' : '' ?>">
                                        <?= $i ?>
                                    </a>

                                <?php endfor; ?>

                                <?php if ($currentPage < $totalPages): ?>
                                    <a class="btn"
                                        href="index.php?page=customers&q=<?= urlencode($keyword) ?>&p=<?= $currentPage + 1 ?>">
                                        Trang sau →
                                    </a>
                                <?php endif; ?>

                            </div>

                        <?php endif; ?>
                    <?php elseif ($page === 'customer_detail' && admin()): ?>

                        <h1 class="title">Chi tiết khách hàng</h1>

                        <?php if (empty($customerDetail)): ?>

                            <div class="message">Không tìm thấy khách hàng.</div>

                        <?php else: ?>

                            <div class="form-box" style="max-width:100%;margin-bottom:25px;">
                                <h2><?= e($customerDetail['customer_name']) ?></h2>

                                <p><strong>Số điện thoại:</strong>
                                    <?= e($customerDetail['phone']) ?>
                                </p>

                                <p><strong>Email:</strong>
                                    <?= e($customerDetail['email'] ?? '') ?>
                                </p>
                                <?php if (!empty($latestCustomerOrder)): ?>

                                    <p>
                                        <strong>Địa chỉ nhận hàng gần nhất:</strong>
                                        <?= e($latestCustomerOrder['address'] ?? 'Chưa có') ?>
                                    </p>

                                    <p>
                                        <strong>Ghi chú đơn hàng gần nhất:</strong>
                                        <?= e($latestCustomerOrder['note'] ?? 'Không có') ?>
                                    </p>

                                    <p>
                                        <strong>Phương thức thanh toán:</strong>
                                        <?= e($latestCustomerOrder['payment_method'] ?? 'Chưa có') ?>
                                    </p>

                                <?php endif; ?>
                                <div class="customer-stats">

                                    <div class="customer-stat-card">
                                        <div>
                                            <p>Tổng số đơn</p>
                                            <h2><?= number_format($customerDetail['total_orders']) ?></h2>
                                        </div>
                                    </div>

                                    <div class="customer-stat-card">
                                        <div>
                                            <p>Đơn hoàn thành</p>
                                            <h2><?= number_format($customerDetail['completed_orders']) ?></h2>
                                        </div>
                                    </div>

                                    <div class="customer-stat-card">
                                        <div>
                                            <p>Đơn chờ xử lý</p>
                                            <h2><?= number_format($customerDetail['pending_orders']) ?></h2>
                                        </div>
                                    </div>

                                    <div class="customer-stat-card">
                                        <div>
                                            <p>Đơn đã hủy</p>
                                            <h2><?= number_format($customerDetail['cancelled_orders']) ?></h2>
                                        </div>
                                    </div>

                                    <div class="customer-stat-card">
                                        <div>
                                            <p>Tổng tiền đã mua</p>
                                            <h2><?= money($customerDetail['total_spent'] ?? 0) ?></h2>
                                        </div>
                                    </div>

                                </div>

                                <p>
                                    <strong>Ngày mua đầu tiên:</strong>
                                    <?= !empty($customerDetail['first_order'])
                                        ? date('d/m/Y', strtotime($customerDetail['first_order']))
                                        : 'Chưa có' ?>
                                </p>

                                <p>
                                    <strong>Đơn gần nhất:</strong>
                                    <?= !empty($customerDetail['last_order'])
                                        ? date('d/m/Y', strtotime($customerDetail['last_order']))
                                        : 'Chưa có' ?>
                                </p>
                            </div>

                            <h2>Danh sách đơn hàng</h2>

                            <form method="get" action="index.php" style="margin-bottom:20px;">
                                <input type="hidden" name="page" value="customer_detail">
                                <input type="hidden" name="phone" value="<?= e($customerDetail['phone']) ?>">

                                <select name="status" onchange="this.form.submit()">
                                    <option value="">Tất cả trạng thái</option>
                                    <option value="pending" <?= ($_GET['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Chờ xử lý</option>
                                    <option value="processing" <?= ($_GET['status'] ?? '') === 'processing' ? 'selected' : '' ?>>Đang xử lý</option>
                                    <option value="shipping" <?= ($_GET['status'] ?? '') === 'shipping' ? 'selected' : '' ?>>Đang giao</option>
                                    <option value="completed" <?= ($_GET['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Hoàn thành</option>
                                    <option value="cancelled" <?= ($_GET['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Đã hủy</option>
                                </select>
                            </form>

                            <div class="table-wrap">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Mã đơn</th>
                                            <th>Ngày đặt</th>
                                            <th>Tổng tiền</th>
                                            <th>Trạng thái</th>
                                            <th>Chi tiết</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($customerOrders as $order): ?>
                                            <tr>
                                                <td><?= e($order['code']) ?></td>
                                                <td><?= date('d/m/Y', strtotime($order['created_at'])) ?></td>
                                                <td><?= money($order['total']) ?></td>
                                                <td>
                                                    <?php
                                                    $statusLabels = [
                                                        'pending' => 'Chờ xác nhận',
                                                        'confirmed' => 'Đã xác nhận',
                                                        'shipping' => 'Đang giao',
                                                        'failed_delivery' => 'Giao hàng thất bại',
                                                        'returned' => 'Đã hoàn hàng',
                                                        'completed' => 'Hoàn thành',
                                                        'cancelled' => 'Đã hủy'
                                                    ];

                                                    $status = $order['status'];
                                                    echo e($statusLabels[$status] ?? $status);
                                                    ?>
                                                </td>
                                                <td>
                                                    <a class="btn" href="index.php?page=order_detail&id=<?= (int)$order['id'] ?>">
                                                        Xem đơn
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php if (empty($customerOrders)): ?>
                                            <tr>
                                                <td colspan="5">Khách hàng chưa có đơn hàng.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                        <?php endif; ?>

                        <p style="margin-top:20px;">
                            <a class="btn" href="index.php?page=customers">← Quay lại khách hàng</a>
                        </p>

                    <?php elseif ($page === 'cart'): ?>

                        <h1 class="title">Giỏ hàng của cậu</h1>

                        <?php if (empty($cartProducts)): ?>

                            <div class="message">Giỏ hàng đang trống.</div>
                            <a class="btn" href="index.php">Tiếp tục mua hàng</a>

                        <?php else: ?>

                            <?php
                            $cartTotal = 0;
                            ?>

                            <form action="actions.php" method="POST"
                                onsubmit="return confirm('Cậu có chắc muốn xóa toàn bộ giỏ hàng?');">

                                <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                <input type="hidden" name="action" value="clear_cart">

                                <button type="submit" class="btn btn-danger">
                                    Xóa toàn bộ giỏ hàng
                                </button>
                            </form>

                            <?php foreach ($cartProducts as $key => $item): ?>
                                <?php
                                $qty = (int)($item['qty'] ?? 0);
                                $price = (float)($item['price'] ?? 0);
                                $lineTotal = $qty * $price;
                                $cartTotal += $lineTotal;
                                ?>

                                <div class="cart-item">
                                    <img
                                        src="<?= e($item['image'] ?: 'assets/placeholder.svg') ?>"
                                        alt="">

                                    <div>
                                        <strong><?= e($item['name'] ?? 'Sản phẩm') ?></strong>

                                        <?php if (!empty($item['color']) || !empty($item['size'])): ?>
                                            <p>
                                                Màu: <?= e($item['color'] ?? '') ?>
                                                |
                                                Size: <?= e($item['size'] ?? '') ?>
                                            </p>
                                        <?php endif; ?>

                                        <p>Đơn giá: <?= money($price) ?></p>

                                        <form action="actions.php" method="POST"
                                            style="display:flex; align-items:center; gap:8px; margin-top:10px;">

                                            <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                            <input type="hidden" name="action" value="update_cart">
                                            <input type="hidden" name="cart_key" value="<?= e($key) ?>">

                                            <label>Số lượng:</label>

                                            <input
                                                type="number"
                                                name="qty"
                                                value="<?= $qty ?>"
                                                min="1"
                                                step="1"
                                                required
                                                style="width:75px; padding:8px; border:1px solid #ccc; border-radius:5px;">

                                            <button type="submit" class="btn">
                                                Cập nhật
                                            </button>
                                        </form>
                                    </div>

                                    <strong><?= money($lineTotal) ?></strong>

                                    <form action="actions.php" method="POST">
                                        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                        <input type="hidden" name="action" value="remove_cart">
                                        <input type="hidden" name="cart_key" value="<?= e($key) ?>">

                                        <button type="submit" class="btn btn-danger">
                                            Xóa
                                        </button>
                                    </form>

                                </div>
                            <?php endforeach; ?>

                            <div style="background:white; padding:20px; margin-top:15px; text-align:right;">
                                <h2>Tổng tiền: <?= money($cartTotal) ?></h2>
                                <a class="btn" href="index.php?page=checkout">Tiến hành đặt hàng</a>
                            </div>

                        <?php endif; ?>

                    <?php elseif ($page === 'checkout'): ?>

                        <?php if (empty($_SESSION['cart'])): ?>

                            <div class="message">Giỏ hàng đang trống.</div>
                            <a class="btn" href="index.php">Quay lại mua hàng</a>

                        <?php else: ?>

                            <div class="form-box">
                                <h1 class="title">Thông tin đặt hàng</h1>

                                <form action="actions.php" method="post">
                                    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
                                    <input type="hidden" name="action" value="order">

                                    <div class="form-group">
                                        <label>Họ và tên</label>
                                        <input type="text" name="name" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Số điện thoại</label>
                                        <input type="tel" name="phone" required>
                                    </div>

                                    <div class="form-group">
                                        <label>Email (không bắt buộc)</label>
                                        <input type="email" name="email">
                                    </div>

                                    <div class="form-group">
                                        <label>Địa chỉ nhận hàng</label>
                                        <textarea name="address" required></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label>Ghi chú</label>
                                        <textarea name="note"></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label>Phương thức thanh toán</label>
                                        <select name="payment" required>
                                            <option value="cod">Thanh toán khi nhận hàng (COD)</option>
                                            <option value="bank">Chuyển khoản ngân hàng</option>
                                            <option value="paypal">PayPal</option>
                                        </select>
                                    </div>

                                    <button class="btn" type="submit">Xác nhận đặt hàng</button>
                                </form>
                            </div>

                        <?php endif; ?>

                    <?php elseif ($page === 'success'): ?>

                        <div class="form-box" style="text-align:center;">
                            <h1>Đặt hàng thành công!</h1>
                            <p>Cảm ơn cậu đã mua hàng tại KingBoxer.</p>

                            <?php if ($orderCode): ?>
                                <h2>Mã đơn hàng: <?= e($orderCode) ?></h2>
                            <?php endif; ?>

                            <a class="btn" href="index.php">Tiếp tục mua hàng</a>
                        </div>

                    <?php else: ?>

                        <div class="message">Trang cậu yêu cầu không tồn tại.</div>
                        <a class="btn" href="index.php">Về trang chủ</a>

                    <?php endif; ?>

            </main>
            <?php if ($isAdminPage): ?>
            </div>
        </div>
    <?php endif; ?>
    <footer class="footer">
        <strong>KINGBOXER</strong>
        <p>Shop đồ lót nam - Chất lượng và thoải mái mỗi ngày</p>
    </footer>

    <script>
        function addVariantRow() {
            const list = document.getElementById('variant-list');

            const row = document.createElement('div');
            row.className = 'variant-row';

            row.innerHTML = `
        <input type="text" name="variant_color[]" placeholder="Màu">
        <input type="text" name="variant_size[]" placeholder="Size">
        <input type="number" name="variant_stock[]" placeholder="Tồn kho" min="0" value="0">
        <input type="text" name="variant_sku[]" placeholder="SKU biến thể">
        <button type="button" onclick="this.parentElement.remove()">Xóa</button>
    `;

            list.appendChild(row);
        }

        function changeProductImage(element) {
            const mainImage = document.getElementById('mainProductImage');

            if (!mainImage) return;

            mainImage.src = element.src;

            document.querySelectorAll('.gallery-thumb').forEach(function(img) {
                img.classList.remove('active');
            });

            element.classList.add('active');
        }
    </script>

</body>

</html>
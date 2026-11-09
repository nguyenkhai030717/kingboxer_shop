<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/app/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Chỉ quản trị viên mới được xuất Excel
if (!admin()) {
    http_response_code(403);
    exit('Bạn không có quyền thực hiện thao tác này.');
}

// Lấy bộ lọc hiện tại
$stockSearch = trim($_GET['q'] ?? '');
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

// Tạo điều kiện tìm kiếm
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

// Lọc trạng thái tồn kho
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

// Lấy toàn bộ dữ liệu, không giới hạn phân trang
$stmt = db()->prepare(
    "SELECT
        p.name AS product_name,
        pv.sku,
        pv.color,
        pv.size,
        pv.stock
     FROM product_variants pv
     INNER JOIN products p ON p.id = pv.product_id
     $whereSql
     ORDER BY p.name ASC, pv.color ASC, pv.size ASC"
);

$stmt->execute($params);

$variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tạo file Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Quản lý tồn kho');

// Tiêu đề
$headers = [
    'STT',
    'Sản phẩm',
    'SKU',
    'Màu sắc',
    'Size',
    'Số lượng tồn',
    'Trạng thái'
];

$sheet->fromArray($headers, null, 'A1');

// Ghi dữ liệu
$row = 2;

foreach ($variants as $index => $variant) {

    $stock = (int)$variant['stock'];

    if ($stock <= 0) {
        $status = 'Hết hàng';
    } elseif ($stock <= 5) {
        $status = 'Sắp hết';
    } else {
        $status = 'Còn hàng';
    }

    $sheet->fromArray([
        $index + 1,
        $variant['product_name'],
        $variant['sku'] ?? '',
        $variant['color'] ?? '',
        $variant['size'] ?? '',
        $stock,
        $status
    ], null, 'A' . $row);

    $row++;
}

// Định dạng tiêu đề
$sheet->getStyle('A1:G1')->getFont()->setBold(true);

$sheet->getStyle('A1:G1')->getAlignment()
    ->setHorizontal(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
    );

// Tự động điều chỉnh độ rộng cột
foreach (range('A', 'G') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Xuất file Excel
$filename = 'quan_ly_ton_kho_' . date('Ymd_His') . '.xlsx';

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();

exit;
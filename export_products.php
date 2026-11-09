
<?php
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Kiểm tra quyền quản trị
if (!admin()) {
    http_response_code(403);
    exit('Bạn không có quyền truy cập.');
}

// Lấy bộ lọc tồn kho
$stockFilter = $_GET['stock'] ?? 'all';

$allowedFilters = [
    'all',
    'in_stock',
    'low_stock',
    'out_stock'
];

if (!in_array($stockFilter, $allowedFilters, true)) {
    $stockFilter = 'all';
}

// Tạo câu truy vấn
$sql = "SELECT id, name, sku, price, sale_price, stock, is_active
        FROM products";

if ($stockFilter === 'in_stock') {
    $sql .= " WHERE stock > 5";
} elseif ($stockFilter === 'low_stock') {
    $sql .= " WHERE stock BETWEEN 1 AND 5";
} elseif ($stockFilter === 'out_stock') {
    $sql .= " WHERE stock <= 0";
}

$sql .= " ORDER BY id DESC";

$products = db()->query($sql)->fetchAll();

// Tạo Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Ton kho KingBoxer');

// Tiêu đề
$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A1', 'DANH SÁCH SẢN PHẨM KINGBOXER');

$sheet->setCellValue('A2', 'Ngày xuất: ' . date('d/m/Y H:i'));

$headers = [
    'ID',
    'Tên sản phẩm',
    'SKU',
    'Giá gốc',
    'Giá khuyến mãi',
    'Tồn kho',
    'Trạng thái tồn kho',
    'Trạng thái hiển thị'
];

$sheet->fromArray($headers, null, 'A4');

// Ghi dữ liệu
$row = 5;

foreach ($products as $product) {
    $stock = (int)$product['stock'];

    if ($stock <= 0) {
        $stockStatus = 'Hết hàng';
    } elseif ($stock <= 5) {
        $stockStatus = 'Sắp hết';
    } else {
        $stockStatus = 'Còn hàng';
    }

    $sheet->fromArray([
        (int)$product['id'],
        $product['name'],
        $product['sku'],
        (float)$product['price'],
        $product['sale_price'] !== null ? (float)$product['sale_price'] : '',
        $stock,
        $stockStatus,
        (int)$product['is_active'] === 1 ? 'Đang bán' : 'Đã ẩn'
    ], null, 'A' . $row);

    $row++;
}

// Định dạng tiêu đề
$sheet->getStyle('A1:H1')->applyFromArray([
    'font' => [
        'bold' => true,
        'size' => 16,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1F2937']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER
    ]
]);

// Định dạng hàng tiêu đề cột
$sheet->getStyle('A4:H4')->applyFromArray([
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '2563EB']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER
    ]
]);

// Tự động căn độ rộng
foreach (range('A', 'H') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Định dạng giá tiền
if ($row > 5) {
    $sheet->getStyle('D5:E' . ($row - 1))
        ->getNumberFormat()
        ->setFormatCode('#,##0 "đ"');
}

// Cố định tiêu đề
$sheet->freezePane('A5');

// Xuất file
$filename = 'kingboxer_ton_kho_' . date('Ymd_His') . '.xlsx';

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

exit;


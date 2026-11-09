
<?php

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// Chỉ admin mới được xuất dữ liệu
if (!admin()) {
    http_response_code(403);
    exit('Bạn không có quyền xuất dữ liệu.');
}

// Lấy lịch sử biến động kho
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

$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tạo file Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Lich su bien dong kho');

$sheet->setCellValue('A1', 'LỊCH SỬ BIẾN ĐỘNG KHO KINGBOXER');
$sheet->mergeCells('A1:J1');

$sheet->setCellValue('A2', 'Ngày xuất: ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A2:J2');

// Tiêu đề cột
$headers = [
    'STT',
    'Sản phẩm',
    'SKU',
    'Màu sắc',
    'Size',
    'Loại biến động',
    'Số lượng thay đổi',
    'Mã tham chiếu',
    'Ghi chú',
    'Thời gian'
];

$sheet->fromArray($headers, null, 'A4');

$sheet->getStyle('A1:J1')->getFont()
    ->setBold(true)->setSize(16);

$sheet->getStyle('A4:J4')->getFont()->setBold(true);

$sheet->getStyle('A4:J4')->getAlignment()
    ->setHorizontal('center');

$movementLabels = [
    'import' => 'Nhập kho',
    'sale' => 'Bán hàng',
    'cancel' => 'Hủy đơn',
    'return' => 'Hoàn hàng',
    'adjustment' => 'Điều chỉnh kho'
];

// Ghi dữ liệu
$row = 5;

foreach ($movements as $index => $movement) {

    $typeLabel = $movementLabels[$movement['movement_type']]
        ?? $movement['movement_type'];

    $sheet->setCellValue('A' . $row, $index + 1);
    $sheet->setCellValue('B' . $row, $movement['product_name']);

    $sheet->setCellValueExplicit(
        'C' . $row,
        $movement['sku'] ?? '',
        DataType::TYPE_STRING
    );

    $sheet->setCellValue('D' . $row, $movement['color'] ?? '');
    $sheet->setCellValue('E' . $row, $movement['size'] ?? '');
    $sheet->setCellValue('F' . $row, $typeLabel);
    $sheet->setCellValue('G' . $row, (int)$movement['quantity_change']);

    $sheet->setCellValueExplicit(
        'H' . $row,
        $movement['reference_code'] ?? '',
        DataType::TYPE_STRING
    );

    $sheet->setCellValue('I' . $row, $movement['note'] ?? '');
    $sheet->setCellValue('J' . $row, $movement['created_at']);

    $row++;
}

// Tự động căn độ rộng cột
foreach (range('A', 'J') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Kẻ khung bảng
if ($row > 5) {
    $sheet->getStyle('A4:J' . ($row - 1))
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(
            \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
        );
}

// Tải file Excel
$filename = 'KingBoxer_LichSuBienDongKho_' . date('Ymd_His') . '.xlsx';

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

exit;

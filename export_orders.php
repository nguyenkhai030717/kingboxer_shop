<?php

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Chỉ admin mới được xuất Excel
if (!admin()) {
    http_response_code(403);
    exit('Bạn không có quyền xuất dữ liệu.');
}

// Lấy điều kiện tìm kiếm và lọc
$keyword = trim($_GET['q'] ?? '');
$orderStatus = $_GET['status'] ?? '';

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

// Tạo câu truy vấn
$sql = "SELECT * FROM orders WHERE 1=1";
$params = [];

if ($orderStatus !== '') {
    $sql .= " AND status = ?";
    $params[] = $orderStatus;
}

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

$sql .= " ORDER BY id DESC";

$stmt = db()->prepare($sql);
$stmt->execute($params);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tạo file Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Danh sach don hang');

// Tiêu đề
$sheet->setCellValue('A1', 'DANH SÁCH ĐƠN HÀNG KINGBOXER');
$sheet->mergeCells('A1:F1');

$sheet->setCellValue('A2', 'Ngày xuất: ' . date('d/m/Y H:i:s'));
$sheet->mergeCells('A2:F2');

// Tiêu đề cột
$headers = [
    'Mã đơn hàng',
    'Tên khách hàng',
    'Số điện thoại',
    'Tổng tiền',
    'Phương thức thanh toán',
    'Trạng thái'
];

$sheet->fromArray($headers, null, 'A4');

// Định dạng tiêu đề
$sheet->getStyle('A1:F1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A4:F4')->getFont()->setBold(true);
$sheet->getStyle('A4:F4')->getAlignment()->setHorizontal('center');

// Chuyển trạng thái sang tiếng Việt
$statusLabels = [
    'pending' => 'Chờ xác nhận',
    'confirmed' => 'Đã xác nhận',
    'shipping' => 'Đang giao',
    'failed_delivery' => 'Giao hàng thất bại',
    'returned' => 'Đã hoàn hàng',
    'completed' => 'Hoàn thành',
    'cancelled' => 'Đã hủy'
];

// Ghi dữ liệu
$row = 5;

foreach ($orders as $order) {

    $sheet->setCellValueExplicit(
        'A' . $row,
        $order['code'],
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
    );

    $sheet->setCellValue('B' . $row, $order['customer_name']);

    $sheet->setCellValueExplicit(
        'C' . $row,
        $order['phone'],
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
    );

    $sheet->setCellValue('D' . $row, (float)$order['total']);

    $sheet->setCellValue('E' . $row, $order['payment_method']);

    $sheet->setCellValue(
        'F' . $row,
        $statusLabels[$order['status']] ?? $order['status']
    );

    $row++;
}

// Định dạng tiền
if ($row > 5) {
    $sheet->getStyle('D5:D' . ($row - 1))
        ->getNumberFormat()
        ->setFormatCode('#,##0" đ"');
}

// Tự động căn độ rộng cột
foreach (range('A', 'F') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Kẻ khung bảng
if ($row > 5) {
    $sheet->getStyle('A4:F' . ($row - 1))
        ->getBorders()
        ->getAllBorders()
        ->setBorderStyle(
            \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
        );
}

// Tải file Excel
$filename = 'KingBoxer_DonHang_' . date('Ymd_His') . '.xlsx';

if (ob_get_length()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

exit;
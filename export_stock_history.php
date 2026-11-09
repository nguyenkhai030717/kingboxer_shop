
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

// Lấy bộ lọc
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

// Tạo điều kiện lọc
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

// Lấy dữ liệu lịch sử nhập kho
$stmt = db()->prepare(
    "SELECT
        si.id,
        p.name AS product_name,
        pv.sku,
        pv.color,
        pv.size,
        si.quantity,
        si.note,
        si.created_at
     FROM stock_imports si
     INNER JOIN products p ON p.id = si.product_id
     LEFT JOIN product_variants pv ON pv.id = si.variant_id
     $whereSql
     ORDER BY si.id DESC"
);

$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tạo file Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Lịch sử nhập kho');

// Tiêu đề
$headers = [
    'STT',
    'Sản phẩm',
    'SKU',
    'Màu sắc',
    'Size',
    'Số lượng nhập',
    'Ghi chú',
    'Thời gian nhập'
];

$sheet->fromArray($headers, null, 'A1');

// Ghi dữ liệu
$row = 2;

foreach ($history as $index => $item) {

    $sheet->fromArray([
        $index + 1,
        $item['product_name'],
        $item['sku'] ?? '',
        $item['color'] ?? '',
        $item['size'] ?? '',
        (int)$item['quantity'],
        $item['note'] ?? '',
        date('d/m/Y H:i', strtotime($item['created_at']))
    ], null, 'A' . $row);

    $row++;
}

// Định dạng tiêu đề
$sheet->getStyle('A1:H1')->getFont()->setBold(true);

$sheet->getStyle('A1:H1')->getAlignment()
    ->setHorizontal(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
    );

// Tự động điều chỉnh độ rộng cột
foreach (range('A', 'H') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Căn giữa một số cột
$sheet->getStyle('A1:A' . max(1, $row - 1))
    ->getAlignment()
    ->setHorizontal(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
    );

// Xuất file Excel
$filename = 'lich_su_nhap_kho_' . date('Ymd_His') . '.xlsx';

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

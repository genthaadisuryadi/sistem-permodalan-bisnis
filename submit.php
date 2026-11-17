<?php
declare(strict_types=1);

/**
 * Backend Handler Pendaftaran Permodalan Bisnis "AI Photobooth"
 * Kompatibel dengan PHP 8.x di cPanel / Shared Hosting
 */

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

/**
 * Helper untuk mengirim respons JSON & menghentikan eksekusi
 */
function sendJsonResponse(int $statusCode, string $status, string $message, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'status'  => $status,
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Validasi metode request wajib POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, 'error', 'Metode request tidak diizinkan. Gunakan metode POST.');
}

// 2. Ambil & sanitasi seluruh input teks
$namaPerusahaanRaw = trim((string)($_POST['nama_perusahaan'] ?? ''));
$namaDirekturRaw   = trim((string)($_POST['nama_direktur'] ?? ''));
$namaAnggotaRaw    = trim((string)($_POST['nama_anggota'] ?? ''));
$programStudiRaw   = trim((string)($_POST['program_studi'] ?? ''));
$semesterRaw       = trim((string)($_POST['semester'] ?? ''));

if (
    $namaPerusahaanRaw === '' ||
    $namaDirekturRaw === '' ||
    $namaAnggotaRaw === '' ||
    $programStudiRaw === '' ||
    $semesterRaw === ''
) {
    sendJsonResponse(400, 'error', 'Seluruh kolom identitas perusahaan/tim wajib diisi secara lengkap.');
}

// Sanitasi input dengan htmlspecialchars
$namaPerusahaan = htmlspecialchars($namaPerusahaanRaw, ENT_QUOTES, 'UTF-8');
$namaDirektur   = htmlspecialchars($namaDirekturRaw, ENT_QUOTES, 'UTF-8');
$namaAnggota    = htmlspecialchars($namaAnggotaRaw, ENT_QUOTES, 'UTF-8');
$programStudi   = htmlspecialchars($programStudiRaw, ENT_QUOTES, 'UTF-8');
$semester       = htmlspecialchars($semesterRaw, ENT_QUOTES, 'UTF-8');

// Validasi daftar nilai semester yang diizinkan
$allowedSemesters = [
    'Semester 1', 'Semester 2', 'Semester 3', 'Semester 4',
    'Semester 5', 'Semester 6', 'Semester 7', 'Semester 8+'
];
if (!in_array($semesterRaw, $allowedSemesters, true)) {
    sendJsonResponse(400, 'error', 'Pilihan semester tidak valid.');
}

// 3. Validasi keberadaan & status file upload proposal_pdf
if (!isset($_FILES['proposal_pdf']) || !is_array($_FILES['proposal_pdf'])) {
    sendJsonResponse(400, 'error', 'File proposal PDF wajib di-upload.');
}

$file = $_FILES['proposal_pdf'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas maksimal konfigurasi server.',
        UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas maksimal form.',
        UPLOAD_ERR_PARTIAL    => 'File hanya ter-upload sebagian. Silakan coba lagi.',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file proposal yang dipilih.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak ditemukan.',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menyimpan file ke disk server.',
        UPLOAD_ERR_EXTENSION  => 'Proses upload dihentikan oleh ekstensi PHP.',
    ];
    $errorMsg = $uploadErrors[$file['error']] ?? 'Terjadi kesalahan saat meng-upload file proposal.';
    sendJsonResponse(400, 'error', $errorMsg);
}

// Validasi ukuran maksimal 10 MB (10 * 1024 * 1024 bytes)
$maxFileSize = 10 * 1024 * 1024;
if ($file['size'] <= 0 || $file['size'] > $maxFileSize) {
    sendJsonResponse(400, 'error', 'Ukuran file proposal tidak valid atau melebihi batas maksimal 10 MB.');
}

// Validasi ekstensi wajib .pdf
$originalName = (string)$file['name'];
$extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($extension !== 'pdf') {
    sendJsonResponse(400, 'error', 'Format file tidak didukung. Harap upload file berekstensi .pdf.');
}

// Validasi MIME type menggunakan finfo & pemeriksaan signature header %PDF-
$tmpPath = $file['tmp_name'];
$mimeType = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $mimeType = (string)finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
    }
} elseif (function_exists('mime_content_type')) {
    $mimeType = (string)mime_content_type($tmpPath);
}

$handle = fopen($tmpPath, 'rb');
$pdfHeader = $handle ? fread($handle, 5) : '';
if ($handle) {
    fclose($handle);
}

if ($mimeType !== 'application/pdf' || $pdfHeader !== '%PDF-') {
    sendJsonResponse(400, 'error', 'File yang di-upload bukan dokumen PDF yang valid.');
}

// 4. Siapkan direktori penyimpanan & proteksi keamanan .htaccess
$baseUploadDir = __DIR__ . '/uploads';
$proposalDir   = $baseUploadDir . '/proposals';

if (!is_dir($proposalDir)) {
    if (!mkdir($proposalDir, 0755, true) && !is_dir($proposalDir)) {
        sendJsonResponse(500, 'error', 'Gagal membuat direktori penyimpanan di server.');
    }
}

// Buat file .htaccess otomatis di dalam folder uploads/ untuk mencegah eksekusi script
$htaccessPath = $baseUploadDir . '/.htaccess';
if (!file_exists($htaccessPath)) {
    $htaccessContent = <<<HTACCESS
# Disable directory browsing and script execution
Options -Indexes -ExecCGI
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .pl .py .cgi .sh
RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .pl .py .cgi .sh
php_flag engine off
<FilesMatch "\.(php|phtml|php3|php4|php5|php7|php8|phps|pl|py|cgi|sh|htaccess)$">
    Require all denied
</FilesMatch>
HTACCESS;
    @file_put_contents($htaccessPath, $htaccessContent);
}

// Buat index.php kosong sebagai proteksi tambahan anti-directory listing
$silentIndex = $baseUploadDir . '/index.php';
if (!file_exists($silentIndex)) {
    @file_put_contents($silentIndex, "<?php // Silence is golden.\n");
}

// 5. Buat nama file PDF yang unik dan aman: YYYYMMDD_HHMMSS_nama-perusahaan.pdf
$slugPerusahaan = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $namaPerusahaanRaw) ?? 'tim');
$slugPerusahaan = trim($slugPerusahaan, '-');
if ($slugPerusahaan === '') {
    $slugPerusahaan = 'tim-pendaftar';
}
$slugPerusahaan = substr($slugPerusahaan, 0, 50);

$timestampPrefix = date('Ymd_His');
$safeFilename    = sprintf('%s_%s.pdf', $timestampPrefix, $slugPerusahaan);
$destinationPath = $proposalDir . '/' . $safeFilename;

// Jika ada pengajuan di detik yang sama dengan nama sama, tambahkan suffix unik
if (file_exists($destinationPath)) {
    $safeFilename    = sprintf('%s_%s_%s.pdf', $timestampPrefix, $slugPerusahaan, substr(bin2hex(random_bytes(3)), 0, 4));
    $destinationPath = $proposalDir . '/' . $safeFilename;
}

if (!move_uploaded_file($tmpPath, $destinationPath)) {
    sendJsonResponse(500, 'error', 'Gagal memindahkan file proposal ke folder penyimpanan server.');
}
@chmod($destinationPath, 0644);

// 6. Catat data pendaftar ke dalam file uploads/data_pendaftar.csv
$csvFilePath  = $baseUploadDir . '/data_pendaftar.csv';
$isNewCsvFile = !file_exists($csvFilePath);

$csvHandle = fopen($csvFilePath, 'ab');
if ($csvHandle === false) {
    sendJsonResponse(500, 'error', 'File proposal tersimpan, namun gagal membuka file log CSV.');
}

if (flock($csvHandle, LOCK_EX)) {
    // Tambahkan UTF-8 BOM & baris header jika file CSV baru pertama kali dibuat agar rapi di Microsoft Excel
    if ($isNewCsvFile) {
        fwrite($csvHandle, "\xEF\xBB\xBF");
        fputcsv($csvHandle, [
            'Waktu Daftar',
            'Nama Perusahaan/Merk',
            'Nama Direktur',
            'Nama Anggota',
            'Program Studi',
            'Semester',
            'Lokasi File PDF'
        ]);
    }

    $waktuDaftar  = date('Y-m-d H:i:s');
    $relativePath = 'uploads/proposals/' . $safeFilename;

    fputcsv($csvHandle, [
        $waktuDaftar,
        $namaPerusahaan,
        $namaDirektur,
        $namaAnggota,
        $programStudi,
        $semester,
        $relativePath
    ]);

    fflush($csvHandle);
    flock($csvHandle, LOCK_UN);
}
fclose($csvHandle);

// 7. Kembalikan respons JSON sukses (HTTP 200)
sendJsonResponse(
    200,
    'success',
    sprintf(
        'Terima kasih! Proposal dari "%s" berhasil ter-upload dan tercatat di sistem seleksi kami.',
        $namaPerusahaan
    ),
    [
        'data' => [
            'nama_perusahaan' => $namaPerusahaan,
            'nama_direktur'   => $namaDirektur,
            'file_tersimpan'  => 'uploads/proposals/' . $safeFilename,
            'waktu_daftar'    => date('Y-m-d H:i:s')
        ]
    ]
);
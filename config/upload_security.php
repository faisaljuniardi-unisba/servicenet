<?php
/**
 * Validasi dan tulis ulang gambar unggahan.
 *
 * Nama/ekstensi dari browser tidak pernah dipercaya. Berkas dibuka oleh GD dan
 * ditulis ulang agar hanya data gambar yang tersimpan pada folder publik.
 */
function storeSafeUploadedImage(array $file, string $targetDir, string $prefix, ?string &$error = null): ?string
{
    $error = null;
    $maxSize = 5 * 1024 * 1024;

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
        !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        $error = 'Unggahan tidak valid.';
        return null;
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > $maxSize) {
        $error = 'Ukuran foto harus maksimal 5 MB.';
        return null;
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        $error = 'Berkas bukan gambar yang valid.';
        return null;
    }

    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 6000 || $height > 6000 || ($width * $height) > 24000000) {
        $error = 'Resolusi foto terlalu besar.';
        return null;
    }

    $mime = $info['mime'] ?? '';
    $handlers = [
        'image/jpeg' => ['create' => 'imagecreatefromjpeg', 'write' => 'imagejpeg', 'extension' => 'jpg'],
        'image/png'  => ['create' => 'imagecreatefrompng',  'write' => 'imagepng',  'extension' => 'png'],
        'image/gif'  => ['create' => 'imagecreatefromgif',  'write' => 'imagegif',  'extension' => 'gif'],
        'image/webp' => ['create' => 'imagecreatefromwebp', 'write' => 'imagewebp', 'extension' => 'webp'],
    ];

    if (!isset($handlers[$mime]) || !function_exists($handlers[$mime]['create']) || !function_exists($handlers[$mime]['write'])) {
        $error = 'Format foto tidak didukung.';
        return null;
    }

    $image = @$handlers[$mime]['create']($file['tmp_name']);
    if ($image === false) {
        $error = 'Isi foto tidak dapat diproses.';
        return null;
    }

    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        imagedestroy($image);
        $error = 'Folder unggahan tidak tersedia.';
        return null;
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(16)) . '.' . $handlers[$mime]['extension'];
    $destination = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if ($mime === 'image/jpeg') {
        $saved = imagejpeg($image, $destination, 85);
    } elseif ($mime === 'image/png') {
        imagealphablending($image, true);
        imagesavealpha($image, true);
        $saved = imagepng($image, $destination, 6);
    } elseif ($mime === 'image/webp') {
        $saved = imagewebp($image, $destination, 82);
    } else {
        $saved = imagegif($image, $destination);
    }

    imagedestroy($image);

    if (!$saved) {
        $error = 'Foto tidak dapat disimpan.';
        return null;
    }

    @chmod($destination, 0644);
    return $filename;
}

<?php
/**
 * Plugin Name: Google Drive
 * Description: Thêm ô nhập link Google Drive (nhiều link) cho bài viết, kiểm tra link hợp lệ và tạo sẵn link xem trước / tải về cho theme.
 * Version: 1.0.0
 * Author: LQCMS
 * Requires PHP: 8.2
 */
defined('LQ_ROOT') || exit;

/**
 * Tách loại và ID từ link Google Drive / Docs. Trả về null nếu không phải link Drive.
 * Hỗ trợ: drive.google.com/file/d/ID/..., /open?id=ID, /drive/folders/ID, docs.google.com/{document|spreadsheets|presentation}/d/ID
 */
function gdrive_parse(string $url): ?array
{
    $p = parse_url($url);
    $host = strtolower($p['host'] ?? '');
    $path = $p['path'] ?? '';
    parse_str($p['query'] ?? '', $q);
    if (!in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
        return null;
    }
    if (preg_match('#^/(?:drive/(?:u/\d+/)?)?folders/([\w-]{10,})#', $path, $m)) {
        return ['type' => 'folder', 'id' => $m[1]];
    }
    if (preg_match('#^/file/d/([\w-]{10,})#', $path, $m)) {
        return ['type' => 'file', 'id' => $m[1]];
    }
    if (preg_match('#^/(document|spreadsheets|presentation|forms)/d/([\w-]{10,})#', $path, $m)) {
        return ['type' => $m[1], 'id' => $m[2]];
    }
    if (!empty($q['id']) && preg_match('/^[\w-]{10,}$/', (string) $q['id'])) {
        return ['type' => 'file', 'id' => (string) $q['id']];
    }
    return null;
}

/**
 * Cho theme dùng khi hiển thị bài: danh sách link đã chuẩn hoá.
 * @return array<int, array{url:string, type:string, id:string, preview:string, download:?string}>
 */
function gdrive_links(int $postId): array
{
    $out = [];
    foreach ((array) post_meta($postId, 'gdrive_links', []) as $url) {
        $info = gdrive_parse((string) $url);
        if (!$info) {
            continue;
        }
        $id = rawurlencode($info['id']);
        $out[] = [
            'url' => (string) $url,
            'type' => $info['type'],
            'id' => $info['id'],
            'preview' => $info['type'] === 'folder'
                ? "https://drive.google.com/embeddedfolderview?id=$id"
                : ($info['type'] === 'file' ? "https://drive.google.com/file/d/$id/preview" : "https://docs.google.com/{$info['type']}/d/$id/preview"),
            'download' => $info['type'] === 'file' ? "https://drive.google.com/uc?export=download&id=$id" : null,
        ];
    }
    return $out;
}

lq_add_post_field('gdrive_links', [
    'label' => 'Link Google Drive',
    'type' => 'url',
    'repeat' => true,                 // nhập nhiều link, kéo để sắp xếp
    'max_items' => 10,
    'add_label' => 'Thêm link',
    'box' => 'Google Drive',
    'context' => 'main',              // 'main' = dưới trình soạn thảo, 'side' = cột phải
    'placeholder' => 'https://drive.google.com/file/d/…/view',
    'help' => 'Hỗ trợ link file, thư mục, Google Docs/Sheets/Slides. Nhớ bật chia sẻ "Bất kỳ ai có đường liên kết".',
    'validate' => function (array $links): ?string {
        foreach ($links as $i => $url) {
            if (!gdrive_parse($url)) {
                return 'Dòng ' . ($i + 1) . ' không phải link Google Drive hợp lệ: ' . $url;
            }
        }
        if (count($links) !== count(array_unique($links))) {
            return 'Có link Google Drive bị nhập trùng.';
        }
        return null;
    },
]);

lq_add_post_field('gdrive_member_only', [
    'label' => 'Chỉ thành viên đăng nhập mới thấy link tải',
    'type' => 'checkbox',
    'box' => 'Google Drive',
    'help' => 'Theme có thể dựa vào tuỳ chọn này để ẩn link với khách.',
    'priority' => 20,
]);

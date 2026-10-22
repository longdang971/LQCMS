<?php
declare(strict_types=1);

namespace LQ\Admin;

use LQ\Content\Taxonomies;
use LQ\Content\Terms;
use LQ\Core\Session;
use LQ\Core\View;

final class TermsController
{
    private static function taxonomy(array $p): string
    {
        $info = Taxonomies::byRoute((string) $p['tax']);
        if (!$info) {
            Kernel::notFound();
        }
        Kernel::require($info['cap']);
        return $info['key'];
    }

    public static function index(array $p): void
    {
        $tax = self::taxonomy($p);
        $q = input_str('q');
        $terms = Terms::all($tax, $q);
        $hier = Terms::info($tax)['hierarchical'];
        if ($hier) {
            $terms = Terms::tree($terms);
        }
        View::render('admin/terms/index', [
            'title' => Terms::info($tax)['label'],
            'tax' => $tax,
            'info' => Terms::info($tax),
            'terms' => $terms,
            'q' => $q,
            'parents' => $hier ? Terms::tree(Terms::all($tax)) : [],
            'defaultCategory' => (int) option('default_category'),
            'errors' => self::pullErrors(),
        ]);
    }

    public static function edit(array $p): void
    {
        $tax = self::taxonomy($p);
        $term = Terms::find((int) $p['id'], $tax);
        if (!$term) {
            Kernel::notFound();
        }
        $hier = Terms::info($tax)['hierarchical'];
        $exclude = $hier ? Terms::descendantIds((int) $term['id']) : [];
        View::render('admin/terms/edit', [
            'title' => 'Sửa ' . Terms::info($tax)['singular'],
            'subject' => $term['name'],
            'tax' => $tax,
            'info' => Terms::info($tax),
            'term' => $term,
            'parents' => $hier
                ? array_values(array_filter(Terms::tree(Terms::all($tax)), fn ($t) => !in_array((int) $t['id'], $exclude, true)))
                : [],
            'errors' => self::pullErrors(),
        ]);
    }

    public static function save(array $p): void
    {
        $tax = self::taxonomy($p);
        $id = (int) input('id', 0);
        if ($id && !Terms::find($id, $tax)) {
            Kernel::notFound();
        }
        [$errors, $savedId] = Terms::save($tax, $_POST, $id);
        $base = '/admin/' . Terms::info($tax)['route'];
        if ($errors) {
            Session::keepOld($_POST);
            $_SESSION['_errors'] = $errors;
            flash('error', reset($errors));
            redirect($id ? "$base/$id/edit" : $base);
        }
        $name = Terms::find($savedId)['name'];
        flash('success', ($id ? 'Đã cập nhật ' : 'Đã thêm ') . Terms::info($tax)['singular'] . ' "' . $name . '".');
        redirect($base);
    }

    public static function destroy(array $p): void
    {
        $tax = self::taxonomy($p);
        $term = Terms::find((int) $p['id'], $tax);
        if (!$term) {
            Kernel::notFound();
        }
        $err = Terms::delete((int) $term['id']);
        $err ? flash('error', $err) : flash('success', 'Đã xoá "' . $term['name'] . '".');
        redirect('/admin/' . Terms::info($tax)['route']);
    }

    /** Gợi ý term khi gõ trong form bài viết: /admin/api/terms?tax=actor&q=... (mặc định là thẻ). */
    public static function searchTags(): void
    {
        Kernel::require('edit_posts');
        $tax = input_str('tax', 'tag');
        if (!Taxonomies::get($tax)) {
            json_response(['ok' => false, 'tags' => []], 404);
        }
        $q = input_str('q');
        $rows = $q === '' ? [] : db()->column(
            'SELECT name FROM {terms} WHERE taxonomy = ? AND (name LIKE ? OR slug LIKE ?) ORDER BY name LIMIT 10',
            [$tax, '%' . addcslashes($q, '%_\\') . '%', '%' . (\LQ\Core\Slug::make($q) ?: addcslashes($q, '%_\\')) . '%']
        );
        json_response(['ok' => true, 'tags' => $rows]);
    }

    private static function pullErrors(): array
    {
        $e = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);
        return $e;
    }
}

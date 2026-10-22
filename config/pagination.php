<?php

/** Pagination sederhana untuk daftar data admin. */
function pagination_settings(): array
{
    $allowed = [10, 25, 50];
    $per_page = (int) ($_GET['per_page'] ?? 10);
    if (!in_array($per_page, $allowed, true)) {
        $per_page = 10;
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    return [$per_page, $page];
}

function pagination_url(int $page, int $per_page): string
{
    $query = $_GET;
    $query['page'] = $page;
    $query['per_page'] = $per_page;
    return '?' . http_build_query($query);
}

function render_page_size_control(int $per_page): void
{
    echo '<form class="page-size-control" method="GET">';
    foreach ($_GET as $key => $value) {
        if (in_array($key, ['page', 'per_page'], true) || is_array($value)) {
            continue;
        }
        echo '<input type="hidden" name="' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<label for="per-page">Tampilkan</label>';
    echo '<select id="per-page" name="per_page" onchange="this.form.submit()">';
    foreach ([10, 25, 50] as $option) {
        $selected = $option === $per_page ? ' selected' : '';
        echo '<option value="' . $option . '"' . $selected . '>' . $option . '</option>';
    }
    echo '</select><span>baris</span></form>';
}

function render_pagination(int $page, int $total_pages, int $per_page): void
{
    if ($total_pages <= 1) {
        return;
    }

    echo '<nav class="pagination" aria-label="Navigasi halaman">';
    if ($page > 1) {
        echo '<a href="' . htmlspecialchars(pagination_url($page - 1, $per_page), ENT_QUOTES, 'UTF-8') . '">‹ Sebelumnya</a>';
    }

    for ($number = 1; $number <= $total_pages; $number++) {
        if ($number !== 1 && $number !== $total_pages && abs($number - $page) > 1) {
            if ($number === $page - 2 || $number === $page + 2) {
                echo '<span class="pagination-ellipsis">…</span>';
            }
            continue;
        }

        $active = $number === $page ? ' class="is-active"' : '';
        echo '<a' . $active . ' href="' . htmlspecialchars(pagination_url($number, $per_page), ENT_QUOTES, 'UTF-8') . '">' . $number . '</a>';
    }

    if ($page < $total_pages) {
        echo '<a href="' . htmlspecialchars(pagination_url($page + 1, $per_page), ENT_QUOTES, 'UTF-8') . '">Berikutnya ›</a>';
    }
    echo '</nav>';
}

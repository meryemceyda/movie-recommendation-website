<?php
declare(strict_types=1);

function list_actions(int $movieId, array $memberships, string $returnList = ''): void
{
    echo '<div class="actions">';
    foreach (LISTS as $key => $label) {
        $saved = isset($memberships[$movieId][$key]);
        echo '<form method="post" action="index.php">';
        csrf_field();
        echo '<input type="hidden" name="action" value="save">';
        echo '<input type="hidden" name="movie_id" value="' . $movieId . '">';
        echo '<input type="hidden" name="list" value="' . e($key) . '">';
        echo '<input type="hidden" name="return_list" value="' . e($returnList) . '">';
        echo '<input type="hidden" name="operation" value="' . ($saved ? 'remove' : 'add') . '">';
        $text = $saved ? $label . ' listesinden çıkar' : $label . ' listesine ekle';
        echo '<button type="submit">' . e($text) . '</button></form>';
    }
    echo '</div>';
}

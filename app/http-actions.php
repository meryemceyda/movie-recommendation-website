<?php
declare(strict_types=1);

function handle_http_action(PDO $db, ?array $user): array
{
    $page = input($_GET, 'page') ?: 'catalog';
    $error = '';
    $email = '';
    $name = '';

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!hash_equals($_SESSION['csrf'], input($_POST, 'csrf'))) {
            http_response_code(403);
            exit('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
        }
        $action = input($_POST, 'action');
        if ($action === 'logout') logout();
        if ($action === 'register' || $action === 'login') {
            $page = $action;
            $email = input($_POST, 'email');
            $name = input($_POST, 'name');
            $error = authenticate($db, $action, $name, $email, input($_POST, 'password')) ?? '';
            if ($error === '') {
                flash($action === 'register' ? 'Hesabınız oluşturuldu.' : 'Giriş yapıldı.');
                redirect('page=lists');
            }
        } elseif ($action === 'save') {
            if (!$user) {
                flash('Film listelerinizi düzenlemek için giriş yapın.');
                redirect('page=login');
            }
            $id = filter_var(input($_POST, 'movie_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id || !save_movie($db, (int) $user['id'], $id, input($_POST, 'list'), input($_POST, 'operation'))) {
                http_response_code(400);
                exit('Geçersiz film veya liste işlemi.');
            }
            flash('Film listeniz güncellendi.');
            $returnList = input($_POST, 'return_list');
            if (isset(LISTS[$returnList])) redirect('page=lists&list=' . urlencode($returnList));
            redirect('id=' . $id);
        } else {
            http_response_code(400);
            exit('Geçersiz işlem.');
        }
    }

    return compact('page','error','email','name');
}

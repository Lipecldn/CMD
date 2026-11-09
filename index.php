<?php
declare(strict_types=1);
session_start();

/*
 * CMD — Catálogo de Marketing Digital
 * Arquivo único: PHP + HTML + CSS + JavaScript.
 *
 * Requisitos atendidos:
 * - Criar conta de microempreendedor (nome, e-mail e senha).
 * - Realizar login.
 * - Cadastrar produto (nome, preço e WhatsApp e/ou Instagram).
 * - Exibir publicamente os produtos cadastrados, sem exigir login.
 * - Associar cada produto ao microempreendedor autenticado.
 *
 * Configuração padrão para XAMPP:
 * Host: localhost | Banco: cmd | Usuário: root | Senha: (vazia)
 */

const DB_HOST = 'localhost';
const DB_NAME = 'cmd';
const DB_USER = 'root';
const DB_PASS = '';

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        // Tenta conectar ao banco já criado.
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            $options
        );
    } catch (PDOException $firstError) {
        // No XAMPP, tenta criar o banco automaticamente caso ainda não exista.
        try {
            $server = new PDO(
                'mysql:host=' . DB_HOST . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                $options
            );
            $server->exec(
                'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` ' .
                'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                $options
            );
        } catch (PDOException $secondError) {
            throw new RuntimeException(
                'Não foi possível conectar ao MySQL. Confira se o MySQL está ativo no XAMPP e se as credenciais estão corretas.'
            );
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS microempreendedores (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            senha VARCHAR(255) NOT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS produtos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            microempreendedor_id INT UNSIGNED NOT NULL,
            nome VARCHAR(160) NOT NULL,
            preco DECIMAL(10,2) NOT NULL,
            whatsapp VARCHAR(30) NULL,
            instagram VARCHAR(190) NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_produto_microempreendedor
                FOREIGN KEY (microempreendedor_id)
                REFERENCES microempreendedores(id)
                ON UPDATE CASCADE
                ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    return $pdo;
}

function redirectWithMessage(string $message, string $type = 'success'): never {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function normalizeWhatsApp(string $number): string {
    return preg_replace('/\D+/', '', $number) ?? '';
}

function validInstagram(string $instagram): bool {
    $instagram = trim($instagram);
    if ($instagram === '') {
        return true;
    }
    if (filter_var($instagram, FILTER_VALIDATE_URL)) {
        return (bool) preg_match('~^https?://(www\.)?instagram\.com/~i', $instagram);
    }
    return (bool) preg_match('/^@?[A-Za-z0-9._]{1,30}$/', $instagram);
}

function instagramUrl(string $instagram): string {
    $instagram = trim($instagram);
    if (filter_var($instagram, FILTER_VALIDATE_URL)) {
        return $instagram;
    }
    return 'https://www.instagram.com/' . ltrim($instagram, '@') . '/';
}

function whatsappUrl(string $number): string {
    $digits = normalizeWhatsApp($number);
    if (strlen($digits) <= 11) {
        $digits = '55' . $digits;
    }
    return 'https://wa.me/' . $digits;
}

$databaseError = '';
$pdo = null;
try {
    $pdo = db();
} catch (Throwable $error) {
    $databaseError = $error->getMessage();
}

if (isset($_GET['sair'])) {
    $_SESSION = [];
    session_destroy();
    session_start();
    redirectWithMessage('Você saiu da sua conta.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    $action = $_POST['action'] ?? '';

    if ($action === 'criar_conta') {
        $nome = trim((string)($_POST['nome'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $senha = (string)($_POST['senha'] ?? '');

        if ($nome === '' || $email === '' || $senha === '') {
            redirectWithMessage('Preencha todos os campos para criar sua conta.', 'error');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirectWithMessage('Informe um e-mail válido.', 'error');
        }
        if (strlen($senha) < 6) {
            redirectWithMessage('A senha deve ter pelo menos 6 caracteres.', 'error');
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO microempreendedores (nome, email, senha) VALUES (?, ?, ?)'
            );
            $stmt->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
            redirectWithMessage('Conta criada com sucesso! Agora, entre com seu e-mail e senha.');
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                redirectWithMessage('Este e-mail já possui uma conta cadastrada.', 'error');
            }
            redirectWithMessage('Não foi possível criar sua conta. Tente novamente.', 'error');
        }
    }

    if ($action === 'login') {
        $email = trim((string)($_POST['email'] ?? ''));
        $senha = (string)($_POST['senha'] ?? '');

        $stmt = $pdo->prepare('SELECT id, nome, email, senha FROM microempreendedores WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($senha, $user['senha'])) {
            redirectWithMessage('E-mail ou senha incorretos. Verifique os dados e tente novamente.', 'error');
        }

        session_regenerate_id(true);
        $_SESSION['microempreendedor'] = [
            'id' => (int)$user['id'],
            'nome' => $user['nome'],
            'email' => $user['email'],
        ];
        redirectWithMessage('Login realizado. Você já pode cadastrar seus produtos.');
    }

    if ($action === 'cadastrar_produto') {
        if (empty($_SESSION['microempreendedor']['id'])) {
            redirectWithMessage('Entre na sua conta para cadastrar um produto.', 'error');
        }

        $nome = trim((string)($_POST['nome_produto'] ?? ''));
        $precoTexto = str_replace(',', '.', trim((string)($_POST['preco'] ?? '')));
        $whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
        $instagram = trim((string)($_POST['instagram'] ?? ''));

        if ($nome === '' || $precoTexto === '' || (!is_numeric($precoTexto)) ||
            (float)$precoTexto <= 0 || ($whatsapp === '' && $instagram === '')) {
            redirectWithMessage('Informe o nome, um preço válido e pelo menos um meio de contato.', 'error');
        }

        $whatsDigits = normalizeWhatsApp($whatsapp);
        if ($whatsapp !== '' && (strlen($whatsDigits) < 10 || strlen($whatsDigits) > 13)) {
            redirectWithMessage('Informe um número válido de WhatsApp com DDD.', 'error');
        }
        if (!validInstagram($instagram)) {
            redirectWithMessage('Informe um usuário ou link válido do Instagram.', 'error');
        }

        $stmt = $pdo->prepare("
            INSERT INTO produtos (microempreendedor_id, nome, preco, whatsapp, instagram)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (int)$_SESSION['microempreendedor']['id'],
            $nome,
            number_format((float)$precoTexto, 2, '.', ''),
            $whatsapp !== '' ? $whatsDigits : null,
            $instagram !== '' ? $instagram : null,
        ]);

        redirectWithMessage('Produto cadastrado e publicado no catálogo!');
    }
}

$usuario = $_SESSION['microempreendedor'] ?? null;
$produtos = [];
if ($pdo instanceof PDO) {
    $stmt = $pdo->query("
        SELECT p.id, p.nome, p.preco, p.whatsapp, p.instagram, m.nome AS vendedor
        FROM produtos p
        INNER JOIN microempreendedores m ON m.id = p.microempreendedor_id
        ORDER BY p.criado_em DESC, p.id DESC
    ");
    $produtos = $stmt->fetchAll();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CMD — Catálogo de Marketing Digital. Conheça produtos de microempreendedores locais.">
    <title>CMD | Catálogo de Marketing Digital</title>
    <style>
        :root {
            --blue-950: #073b83;
            --blue-900: #084b9f;
            --blue-800: #075fc5;
            --blue-700: #0875e8;
            --blue-600: #1688f7;
            --blue-100: #dceeff;
            --blue-50: #f1f8ff;
            --ink: #102d59;
            --muted: #637b9e;
            --line: #e1ebf7;
            --white: #fff;
            --shadow: 0 18px 55px rgba(12, 91, 184, .13);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            font-family: Inter, "Segoe UI", Arial, sans-serif;
            background:
                radial-gradient(ellipse at 12% 30%, rgba(91, 173, 255, .24), transparent 36%),
                radial-gradient(ellipse at 90% 42%, rgba(111, 190, 255, .22), transparent 34%),
                linear-gradient(180deg, #eaf5ff 0%, #f7fbff 56%, #eaf4ff 100%);
        }

        button, input { font: inherit; }
        button { cursor: pointer; }
        a { color: inherit; }

        .top-area {
            position: relative;
            overflow: hidden;
            padding: 26px 5.5% 116px;
            color: white;
            background:
                radial-gradient(ellipse at 77% 8%, rgba(45, 151, 255, .9), transparent 32%),
                linear-gradient(115deg, #06428f 0%, #075fc5 52%, #1688f7 100%);
        }
        .top-area::before, .top-area::after {
            content: "";
            position: absolute;
            pointer-events: none;
            border-radius: 50%;
            background: rgba(255,255,255,.10);
        }
        .top-area::before {
            width: 75vw; height: 210px; left: -13%; bottom: -140px;
            transform: rotate(-7deg);
        }
        .top-area::after {
            width: 66vw; height: 190px; right: -15%; bottom: -130px;
            background: rgba(173, 218, 255, .23);
            transform: rotate(7deg);
        }
        .header {
            position: relative; z-index: 1;
            max-width: 1420px; margin: 0 auto;
            display: grid; grid-template-columns: auto minmax(180px, 1fr) auto;
            align-items: center; gap: 34px;
        }
        .brand { display: flex; align-items: center; gap: 16px; text-decoration: none; }
        .brand-mark {
            width: 58px; height: 58px; display: grid; place-items: center;
            border: 2px solid rgba(255,255,255,.65); border-radius: 18px;
            background: rgba(255,255,255,.13); font-size: 30px;
            box-shadow: 0 8px 22px rgba(0, 30, 90, .15);
        }
        .brand-name { font-size: 37px; line-height: 1; font-weight: 850; letter-spacing: -1.5px; }
        .brand-sub { margin-top: 6px; font-size: 13px; opacity: .9; letter-spacing: .1px; }
        .search-box {
            display: flex; align-items: center; gap: 12px; width: 100%;
            min-height: 52px; padding: 0 20px; border-radius: 30px;
            background: rgba(255,255,255,.88); color: var(--blue-900);
            box-shadow: 0 8px 24px rgba(0, 40, 100, .10);
        }
        .search-box span { font-size: 23px; }
        .search-box input {
            width: 100%; border: 0; outline: 0; background: transparent;
            color: var(--ink); font-size: 15px;
        }
        .search-box input::placeholder { color: #7890b0; }
        .btn {
            border: 0; border-radius: 999px; padding: 14px 24px;
            font-weight: 750; transition: transform .18s, box-shadow .18s, background .18s;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn-light {
            color: var(--blue-800); background: white;
            box-shadow: 0 8px 22px rgba(0, 35, 90, .16);
        }
        .btn-primary {
            color: white; background: linear-gradient(110deg, #075fc5, #168bff);
            box-shadow: 0 10px 24px rgba(0, 103, 222, .24);
        }
        .btn-outline {
            color: var(--blue-800); background: white; border: 1px solid #cfe4fb;
        }
        .header-actions { display: flex; align-items: center; gap: 10px; }
        .welcome { color: white; font-size: 13px; white-space: nowrap; }

        .page {
            width: min(1240px, calc(100% - 48px));
            margin: -48px auto 54px; position: relative; z-index: 2;
        }
        .catalog {
            min-height: 510px; padding: 30px 36px 42px;
            border: 1px solid rgba(255,255,255,.9); border-radius: 25px;
            background: rgba(255,255,255,.96); box-shadow: var(--shadow);
        }
        .catalog-heading {
            display: flex; align-items: center; gap: 20px;
            padding-bottom: 24px; border-bottom: 1px solid var(--line);
        }
        .store-icon {
            width: 58px; height: 58px; flex: 0 0 58px;
            display: grid; place-items: center; border-radius: 17px;
            color: var(--blue-700); background: var(--blue-50); font-size: 30px;
        }
        .catalog-heading h1 { margin: 0; font-size: clamp(22px, 3vw, 30px); letter-spacing: -.7px; }
        .catalog-heading p { margin: 7px 0 0; color: var(--muted); font-size: 16px; }
        .catalog-content { padding-top: 30px; }
        .empty-state {
            min-height: 350px; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-align: center;
            padding: 24px;
        }
        .empty-art {
            width: 142px; height: 120px; margin-bottom: 12px;
            display: grid; place-items: center; position: relative;
            border-radius: 50%; background: radial-gradient(circle, #e7f3ff 0%, #f8fbff 72%);
        }
        .empty-art .box-icon { font-size: 76px; filter: drop-shadow(0 9px 8px rgba(0, 104, 224, .18)); }
        .spark { position: absolute; color: #46b8f4; font-size: 22px; }
        .spark.one { top: 3px; left: 9px; }
        .spark.two { right: 3px; bottom: 17px; }
        .empty-state h2 { margin: 4px 0 8px; font-size: clamp(22px, 3vw, 29px); letter-spacing: -.5px; }
        .empty-state p { margin: 0 0 26px; color: var(--muted); font-size: 17px; }
        .empty-state .btn { min-width: 220px; padding: 16px 28px; font-size: 17px; }
        .plus { font-size: 23px; margin-right: 7px; vertical-align: -1px; }

        .product-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
        }
        .product-card {
            overflow: hidden; border: 1px solid #e0eaf5; border-radius: 17px;
            background: white; box-shadow: 0 7px 22px rgba(22, 75, 130, .07);
            transition: transform .2s, box-shadow .2s;
        }
        .product-card:hover { transform: translateY(-4px); box-shadow: 0 13px 28px rgba(22, 75, 130, .12); }
        .product-art {
            height: 145px; display: grid; place-items: center;
            background: linear-gradient(145deg, #e5f3ff, #f7fbff);
            color: #1684ed; font-size: 57px;
        }
        .product-info { padding: 17px; }
        .product-info h3 { margin: 0 0 9px; font-size: 18px; overflow-wrap: anywhere; }
        .price { margin: 0 0 8px; color: #14966e; font-size: 21px; font-weight: 800; }
        .seller { margin: 0 0 15px; color: var(--muted); font-size: 13px; }
        .contact-links { display: flex; flex-wrap: wrap; gap: 8px; }
        .contact-links a {
            padding: 8px 11px; border-radius: 9px; text-decoration: none;
            color: var(--blue-800); background: #edf6ff; font-size: 12px; font-weight: 700;
        }
        .contact-links a:hover { background: #dceeff; }
        .no-results { text-align: center; color: var(--muted); padding: 55px 15px; }

        .footer {
            position: relative; overflow: hidden; padding: 22px 5.5%;
            background: rgba(255,255,255,.82); border-top: 1px solid #e4effa;
        }
        .footer-inner {
            max-width: 1420px; margin: 0 auto; display: flex;
            justify-content: space-between; align-items: center; gap: 20px;
        }
        .footer-brand { display: flex; align-items: center; gap: 13px; color: var(--blue-800); }
        .footer-brand strong { font-size: 25px; }
        .footer-brand span { color: var(--muted); font-size: 14px; }
        .footer-social { display: flex; gap: 12px; }
        .footer-social a {
            width: 39px; height: 39px; display: grid; place-items: center;
            border-radius: 50%; background: #eaf4ff; color: var(--blue-800);
            text-decoration: none; font-size: 17px;
        }

        .modal-backdrop {
            display: none; position: fixed; inset: 0; z-index: 20;
            align-items: center; justify-content: center; padding: 20px;
            background: rgba(5, 28, 64, .56); backdrop-filter: blur(5px);
        }
        .modal-backdrop.open { display: flex; }
        .modal {
            width: min(460px, 100%); max-height: min(90vh, 760px); overflow-y: auto;
            padding: 28px; border-radius: 22px; background: white;
            box-shadow: 0 25px 80px rgba(0, 25, 65, .25);
            animation: appear .18s ease-out;
        }
        @keyframes appear { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
        .modal-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 15px; margin-bottom: 22px; }
        .modal-head h2 { margin: 0; font-size: 24px; }
        .modal-head p { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
        .close-modal {
            width: 34px; height: 34px; border: 0; border-radius: 50%;
            background: #f0f6fd; color: var(--ink); font-size: 21px;
        }
        .field { display: block; margin-bottom: 16px; }
        .field span { display: block; margin-bottom: 7px; font-size: 13px; font-weight: 700; color: #29466f; }
        .field input {
            width: 100%; height: 47px; padding: 0 13px;
            border: 1px solid #d9e5f2; border-radius: 11px; outline: none;
            background: #fbfdff; color: var(--ink);
        }
        .field input:focus { border-color: #1688f7; box-shadow: 0 0 0 3px rgba(22,136,247,.12); }
        .field small { display: block; margin-top: 6px; color: #8294ad; font-size: 11px; }
        .modal .btn { width: 100%; margin-top: 5px; }
        .modal-switch { margin: 17px 0 0; text-align: center; color: var(--muted); font-size: 13px; }
        .text-button { border: 0; padding: 0; color: var(--blue-700); background: none; font-weight: 800; }
        .flash {
            margin: 0 0 18px; padding: 13px 16px; border-radius: 12px;
            font-size: 14px; font-weight: 600;
        }
        .flash.success { color: #08734f; background: #e9fbf3; border: 1px solid #c7f1df; }
        .flash.error { color: #a52b36; background: #fff0f1; border: 1px solid #ffd5d8; }
        .db-warning {
            margin: 0 0 18px; padding: 13px 16px; border-radius: 12px;
            color: #805300; background: #fff8e8; border: 1px solid #f5dfad; font-size: 13px;
        }

        @media (max-width: 850px) {
            .top-area { padding: 22px 24px 95px; }
            .header { grid-template-columns: 1fr auto; gap: 18px; }
            .search-box { grid-column: 1 / -1; grid-row: 2; }
            .brand-name { font-size: 31px; }
            .brand-mark { width: 50px; height: 50px; }
            .page { margin-top: -42px; }
            .catalog { padding: 25px 23px 30px; }
        }
        @media (max-width: 560px) {
            .top-area { padding: 19px 17px 82px; }
            .header { gap: 13px; }
            .brand { gap: 10px; }
            .brand-name { font-size: 27px; }
            .brand-sub { font-size: 10px; }
            .brand-mark { width: 43px; height: 43px; font-size: 23px; border-radius: 14px; }
            .header-actions { flex-direction: column; align-items: flex-end; }
            .header-actions .btn { padding: 11px 14px; font-size: 12px; }
            .welcome { display: none; }
            .search-box { min-height: 46px; }
            .page { width: calc(100% - 24px); margin-top: -34px; margin-bottom: 30px; }
            .catalog { min-height: 480px; padding: 21px 16px 25px; border-radius: 19px; }
            .catalog-heading { gap: 12px; padding-bottom: 18px; }
            .store-icon { width: 46px; height: 46px; flex-basis: 46px; font-size: 24px; }
            .catalog-heading h1 { font-size: 21px; }
            .catalog-heading p { font-size: 13px; line-height: 1.4; }
            .empty-state { min-height: 350px; padding: 16px 5px; }
            .empty-state p { font-size: 14px; line-height: 1.5; }
            .empty-state .btn { min-width: 190px; }
            .footer-inner { flex-direction: column; text-align: center; }
            .footer-brand { flex-wrap: wrap; justify-content: center; }
            .footer-brand span { font-size: 12px; }
            .modal { padding: 23px 19px; }
        }
    </style>
</head>
<body>
<header class="top-area">
    <div class="header">
        <a class="brand" href="index.php" aria-label="CMD - início">
            <div class="brand-mark" aria-hidden="true">🛍️</div>
            <div>
                <div class="brand-name">CMD</div>
                <div class="brand-sub">Catálogo de Marketing Digital</div>
            </div>
        </a>

        <label class="search-box" aria-label="Pesquisar produtos">
            <span aria-hidden="true">⌕</span>
            <input id="searchInput" type="search" placeholder="Explore produtos..." autocomplete="off">
        </label>

        <div class="header-actions">
            <?php if ($usuario): ?>
                <span class="welcome">Olá, <?= e((string)$usuario['nome']) ?>!</span>
                <button class="btn btn-light" type="button" onclick="openModal('productModal')">
                    <span class="plus">＋</span> Criar Produto
                </button>
                <a class="btn btn-outline" href="?sair=1" style="text-decoration:none">Sair</a>
            <?php else: ?>
                <button class="btn btn-light" type="button" onclick="openModal('loginModal')">Entrar</button>
                <button class="btn btn-light" type="button" onclick="openModal('registerModal')">Criar Conta</button>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="page">
    <section class="catalog" aria-labelledby="catalogTitle">
        <div class="catalog-heading">
            <div class="store-icon" aria-hidden="true">▤</div>
            <div>
                <h1 id="catalogTitle">Catálogo de Produtos</h1>
                <p>Descubra produtos incríveis de microempreendedores locais!</p>
            </div>
        </div>

        <div class="catalog-content">
            <?php if ($flash): ?>
                <div class="flash <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <?php if ($databaseError !== ''): ?>
                <div class="db-warning">
                    <strong>Banco de dados indisponível.</strong><br>
                    <?= e($databaseError) ?>
                    <br>Abra o XAMPP e verifique se o MySQL está iniciado.
                </div>
            <?php endif; ?>

            <?php if (count($produtos) === 0): ?>
                <div class="empty-state" id="emptyState">
                    <div class="empty-art" aria-hidden="true">
                        <span class="spark one">✦</span>
                        <span class="box-icon">📦</span>
                        <span class="spark two">✧</span>
                    </div>
                    <h2>Nenhum produto encontrado ainda!</h2>
                    <p>Seja o primeiro a cadastrar um produto no CMD.</p>
                    <?php if ($usuario): ?>
                        <button class="btn btn-primary" type="button" onclick="openModal('productModal')">
                            <span class="plus">＋</span> Criar Produto
                        </button>
                    <?php else: ?>
                        <button class="btn btn-primary" type="button" onclick="openModal('loginModal')">
                            <span class="plus">＋</span> Entrar para cadastrar
                        </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="product-grid" id="productGrid">
                    <?php foreach ($produtos as $produto): ?>
                        <article class="product-card" data-search="<?= e(mb_strtolower($produto['nome'] . ' ' . $produto['vendedor'], 'UTF-8')) ?>">
                            <div class="product-art" aria-hidden="true">🛍️</div>
                            <div class="product-info">
                                <h3><?= e($produto['nome']) ?></h3>
                                <p class="price">R$ <?= number_format((float)$produto['preco'], 2, ',', '.') ?></p>
                                <p class="seller">Anunciado por <?= e($produto['vendedor']) ?></p>
                                <div class="contact-links">
                                    <?php if (!empty($produto['whatsapp'])): ?>
                                        <a href="<?= e(whatsappUrl($produto['whatsapp'])) ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                                    <?php endif; ?>
                                    <?php if (!empty($produto['instagram'])): ?>
                                        <a href="<?= e(instagramUrl($produto['instagram'])) ?>" target="_blank" rel="noopener noreferrer">Instagram</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="no-results" id="noResults" hidden>Nenhum produto corresponde à sua pesquisa.</div>
            <?php endif; ?>
        </div>
    </section>
</main>

<footer class="footer">
    <div class="footer-inner">
        <div class="footer-brand">
            <strong>🛍️ CMD</strong>
            <span>│</span>
            <span>Conectando empreendedores e consumidores 💙</span>
        </div>
        <div class="footer-social" aria-label="Canais de contato">
            <a href="https://www.instagram.com/" target="_blank" rel="noopener noreferrer" aria-label="Instagram">◎</a>
            <a href="https://www.whatsapp.com/" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp">◉</a>
            <a href="mailto:contato@cmd.com.br" aria-label="E-mail">✉</a>
        </div>
    </div>
</footer>

<!-- Modal de login -->
<div class="modal-backdrop" id="loginModal" role="dialog" aria-modal="true" aria-labelledby="loginTitle">
    <div class="modal">
        <div class="modal-head">
            <div><h2 id="loginTitle">Entrar no CMD</h2><p>Acesse sua conta de microempreendedor.</p></div>
            <button class="close-modal" type="button" onclick="closeModal('loginModal')" aria-label="Fechar">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <label class="field"><span>E-mail</span><input type="email" name="email" required autocomplete="email" placeholder="seuemail@exemplo.com"></label>
            <label class="field"><span>Senha</span><input type="password" name="senha" required autocomplete="current-password" placeholder="Digite sua senha"></label>
            <button class="btn btn-primary" type="submit">Entrar</button>
        </form>
        <p class="modal-switch">Ainda não tem conta? <button class="text-button" type="button" onclick="switchModal('loginModal','registerModal')">Criar conta</button></p>
    </div>
</div>

<!-- Modal de criação de conta -->
<div class="modal-backdrop" id="registerModal" role="dialog" aria-modal="true" aria-labelledby="registerTitle">
    <div class="modal">
        <div class="modal-head">
            <div><h2 id="registerTitle">Criar conta</h2><p>Cadastre-se para divulgar seus produtos.</p></div>
            <button class="close-modal" type="button" onclick="closeModal('registerModal')" aria-label="Fechar">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="criar_conta">
            <label class="field"><span>Nome</span><input type="text" name="nome" required maxlength="120" autocomplete="name" placeholder="Seu nome"></label>
            <label class="field"><span>E-mail</span><input type="email" name="email" required maxlength="190" autocomplete="email" placeholder="seuemail@exemplo.com"></label>
            <label class="field"><span>Senha</span><input type="password" name="senha" required minlength="6" autocomplete="new-password" placeholder="Mínimo de 6 caracteres"></label>
            <button class="btn btn-primary" type="submit">Criar minha conta</button>
        </form>
        <p class="modal-switch">Já tem uma conta? <button class="text-button" type="button" onclick="switchModal('registerModal','loginModal')">Fazer login</button></p>
    </div>
</div>

<!-- Modal de cadastro de produto -->
<div class="modal-backdrop" id="productModal" role="dialog" aria-modal="true" aria-labelledby="productTitle">
    <div class="modal">
        <div class="modal-head">
            <div><h2 id="productTitle">Criar produto</h2><p>Preencha os dados para publicar no catálogo.</p></div>
            <button class="close-modal" type="button" onclick="closeModal('productModal')" aria-label="Fechar">×</button>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="cadastrar_produto">
            <label class="field"><span>Nome do produto</span><input type="text" name="nome_produto" required maxlength="160" placeholder="Ex.: Camiseta personalizada"></label>
            <label class="field"><span>Preço (R$)</span><input type="number" name="preco" required min="0.01" step="0.01" placeholder="Ex.: 49,90"></label>
            <label class="field"><span>WhatsApp</span><input type="tel" name="whatsapp" inputmode="tel" placeholder="DDD + número"><small>Informe um número válido com DDD, se desejar.</small></label>
            <label class="field"><span>Instagram</span><input type="text" name="instagram" placeholder="@seuperfil ou link do perfil"><small>Informe um usuário ou link válido. Preencha WhatsApp, Instagram ou ambos.</small></label>
            <button class="btn btn-primary" type="submit">Publicar produto</button>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
            const firstInput = modal.querySelector('input:not([type="hidden"])');
            if (firstInput) setTimeout(() => firstInput.focus(), 80);
        }
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.remove('open');
        if (!document.querySelector('.modal-backdrop.open')) {
            document.body.style.overflow = '';
        }
    }

    function switchModal(currentId, nextId) {
        closeModal(currentId);
        openModal(nextId);
    }

    document.querySelectorAll('.modal-backdrop').forEach((backdrop) => {
        backdrop.addEventListener('click', (event) => {
            if (event.target === backdrop) closeModal(backdrop.id);
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.open').forEach((modal) => closeModal(modal.id));
        }
    });

    const searchInput = document.getElementById('searchInput');
    const productCards = document.querySelectorAll('.product-card');
    const noResults = document.getElementById('noResults');

    if (searchInput && productCards.length) {
        searchInput.addEventListener('input', () => {
            const term = searchInput.value.trim().toLocaleLowerCase('pt-BR');
            let visible = 0;
            productCards.forEach((card) => {
                const content = (card.dataset.search || '').toLocaleLowerCase('pt-BR');
                const matches = content.includes(term);
                card.hidden = !matches;
                if (matches) visible++;
            });
            if (noResults) noResults.hidden = visible !== 0;
        });
    }
</script>
</body>
</html>

<?php
// /login/index.php
declare(strict_types=1);

mb_internal_encoding('UTF-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../app/config/conexao.php';

$erro = '';

// Se já estiver logado, manda para /app
if (!empty($_SESSION['user_id'])) {
    header('Location: ../app/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');

    if ($email === '' || $senha === '') {
        $erro = 'Informe e-mail e senha.';
    } else {
        $st = $pdo->prepare("
      SELECT USU_ID, USU_NOME, USU_EMAIL, USU_SENHA_HASH, USU_PERFIL, USU_STATUS
      FROM usuarios
      WHERE USU_EMAIL = ?
      LIMIT 1
    ");
        $st->execute([$email]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        $ok = $u
            && ($u['USU_STATUS'] === 'ATIVO')
            && password_verify($senha, $u['USU_SENHA_HASH']);

        if ($ok) {
            $_SESSION['user_id']     = (int)$u['USU_ID'];
            $_SESSION['user_nome']   = (string)$u['USU_NOME'];
            $_SESSION['user_email']  = (string)$u['USU_EMAIL'];
            $_SESSION['user_perfil'] = (string)$u['USU_PERFIL'];

            $pdo->prepare("UPDATE usuarios SET USU_ULTIMO_LOGIN = NOW() WHERE USU_ID = ?")
                ->execute([(int)$u['USU_ID']]);

            header('Location: ../app/');
            exit;
        }

        $erro = 'E-mail ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-br">

<head>
    <meta charset="utf-8" />
    <title>Login - DRE</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        :root {
            --primary: #2563eb;
            --primary-soft: rgba(37, 99, 235, .12);
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Poppins', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: radial-gradient(circle at top left, #dbeafe 0, transparent 50%),
                radial-gradient(circle at bottom right, #fee2e2 0, transparent 50%),
                #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .login-wrapper {
            width: 100%;
            max-width: 960px;
            background: #fff;
            border-radius: 1.5rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .35);
            overflow: hidden;
            display: grid;
            grid-template-columns: 1.2fr 1fr;
        }

        @media (max-width:768px) {
            .login-wrapper {
                grid-template-columns: 1fr;
            }

            .login-hero {
                display: none;
            }
        }

        .login-main {
            padding: 32px 28px;
        }

        @media (min-width:992px) {
            .login-main {
                padding: 40px 48px;
            }
        }

        .login-hero {
            background: linear-gradient(135deg, #1d4ed8, #3b82f6);
            color: #e5edff;
            padding: 32px 32px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .login-pill {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .3rem .8rem;
            border-radius: 999px;
            background: rgba(15, 23, 42, .18);
            font-size: .75rem;
        }

        .login-title {
            font-size: 1.8rem;
            font-weight: 600;
        }

        .login-sub {
            font-size: .9rem;
            opacity: .85;
        }

        .login-card-title {
            font-size: 1.4rem;
            font-weight: 600;
            color: #111827;
        }

        .tiny {
            font-size: .78rem;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #9ca3af;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            border-radius: 999px;
            padding: .35rem .85rem;
            background: var(--primary-soft);
            color: #1e40af;
            font-size: .9rem;
            font-weight: 500;
        }

        .brand-logo {
            width: 32px;
            height: 32px;
            border-radius: 999px;
            background: linear-gradient(135deg, #2563eb, #4f46e5);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #eff6ff;
            font-size: 1.1rem;
            box-shadow: 0 0 0 3px rgba(191, 219, 254, .7);
        }

        .form-control {
            border-radius: .9rem;
            border-color: #e5e7eb;
            padding: .6rem .85rem;
            font-size: .9rem;
        }

        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 .18rem rgba(37, 99, 235, .18);
        }

        .btn-primary {
            border-radius: .9rem;
            padding: .6rem 1rem;
            font-weight: 500;
            background: #2563eb;
            border-color: #2563eb;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }
    </style>
</head>

<body>
    <div class="login-wrapper">

        <div class="login-main">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div class="brand-badge">
                    <div class="brand-logo"><i class="bi bi-graph-up"></i></div>
                    <span>DRE Dashboard</span>
                </div>
                <span class="px-3 py-1 rounded-pill" style="font-size:.75rem;background:rgba(15,23,42,.04);color:#4b5563;">Versão 1.0</span>
            </div>

            <div class="mb-4">
                <div class="tiny mb-1">Acesso seguro</div>
                <div class="login-card-title mb-1">Entre para acessar seu DRE</div>
                <div class="text-muted" style="font-size:.9rem;">
                    Faça login para visualizar relatórios e acompanhar seus resultados.
                </div>
            </div>

            <?php if ($erro): ?>
                <div class="alert alert-danger py-2 px-3 small" role="alert">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" class="mt-3">
                <div class="mb-3">
                    <label class="form-label" for="email">E-mail</label>
                    <input type="email" class="form-control" id="email" name="email"
                        placeholder="Digite seu email" autocomplete="email" required>
                </div>

                <div class="mb-2">
                    <label class="form-label" for="senha">Senha</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="senha" name="senha" placeholder="Senha" autocomplete="current-password" required>
                        <button class="btn btn-outline-secondary" type="button" id="toggleSenha" title="Mostrar/ocultar senha">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 mb-3">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Entrar
                </button>
            </form>
        </div>

        <div class="login-hero">
            <div>
                <div class="login-pill mb-3">
                    <i class="bi bi-shield-lock"></i><span>Ambiente protegido</span>
                </div>
                <div class="login-title mb-2">Controle total <br>do seu DRE</div>
                <div class="login-sub">
                    Acompanhe receitas, despesas e indicadores em uma interface simples,
                    rápida e responsiva.
                </div>
            </div>
            <div style="font-size:.75rem;opacity:.85;">
                <i class="bi bi-check2-circle me-1"></i>Seus dados ficam apenas nesta aplicação.
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggle = document.getElementById('toggleSenha');
            const input = document.getElementById('senha');
            if (toggle && input) {
                toggle.addEventListener('click', () => {
                    const isPassword = input.getAttribute('type') === 'password';
                    input.setAttribute('type', isPassword ? 'text' : 'password');
                    toggle.innerHTML = isPassword ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
                });
            }
        });
    </script>
</body>

</html>
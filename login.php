<?php
$pageTitle = 'Login';
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = cleanInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = APP_LANG === 'en' ? 'Email and password are required' : 'Email dan password harus diisi';
    } else {
        $db = Database::getInstance();

        $hasLastLogin = $db->fetchAll("SHOW COLUMNS FROM users LIKE 'last_login'");
        if (empty($hasLastLogin)) {
            @$db->query("ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER updated_at");
        }

        $user = $db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            @$db->update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int)$user['id']]);
            setFlash('success', T('wel_back', 'Selamat datang') . ', ' . $user['name'] . '!');
            redirect('index.php');
        } else {
            $error = T('login_invalid', 'Email atau password salah');
        }
    }
}

$qsLoginLang = $_GET;
unset($qsLoginLang['lang']);
$loginLangBase = http_build_query($qsLoginLang);
$loginLangSuffix = $loginLangBase ? '?' . $loginLangBase . '&' : '?';
?>
<!DOCTYPE html>
<html lang="<?= APP_LANG === 'en' ? 'en' : 'id' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | St. Regis Bali - Engineering Daily Log</title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>logo.jpeg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Playfair Display', 'serif'],
                    },
                    colors: {
                        primary: '#111111',
                        secondary: '#666666',
                        accent: '#c9a227',
                        accent2: '#e5c45c',
                        surface: '#ffffff',
                        muted: '#f5f5f5',
                        border: '#e5e5e5',
                    },
                    borderRadius: {
                        'premium': '22px',
                        'card': '15px',
                        'xlarge': '28px',
                    },
                    boxShadow: {
                        'premium-xl': '0 35px 80px -20px rgba(17,17,17,0.25)',
                        'gold-glow': '0 18px 40px -10px rgba(201,162,39,0.55)',
                    },
                    animation: {
                        'pulse-glow': 'pulseGlow 2s ease-in-out infinite',
                        'shimmer': 'shimmer 2.2s linear infinite',
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'slide-up': 'slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1)',
                        'float': 'float 3.5s ease-in-out infinite',
                        'float-slow': 'float 6s ease-in-out infinite',
                        'shakeX': 'shakeX 0.5s cubic-bezier(.36,.07,.19,.97)',
                    },
                    keyframes: {
                        pulseGlow: {
                            '0%, 100%': { boxShadow: '0 0 5px rgba(201,162,39,0.35), 0 0 22px rgba(201,162,39,0.12)' },
                            '50%': { boxShadow: '0 0 22px rgba(201,162,39,0.6), 0 0 48px rgba(201,162,39,0.22)' },
                        },
                        shimmer: { '0%': { backgroundPosition: '-1200px 0' }, '100%': { backgroundPosition: '1200px 0' } },
                        fadeIn:  { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        slideUp: { '0%': { opacity: '0', transform: 'translateY(40px) scale(0.97)' }, '100%': { opacity: '1', transform: 'translateY(0) scale(1)' } },
                        float:   { '0%, 100%': { transform: 'translateY(0px)' }, '50%': { transform: 'translateY(-10px)' } },
                        shakeX:  { '0%,100%':{transform:'translateX(0)'},'10%,30%,50%,70%,90%':{transform:'translateX(-6px)'},'20%,40%,60%,80%':{transform:'translateX(6px)'} },
                    },
                }
            }
        }
    </script>
    <style>
        .hero-pattern-grid {
            background-image:
                linear-gradient(rgba(255,255,255,0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.06) 1px, transparent 1px);
            background-size: 34px 34px;
        }
        .shimmer-btn {
            background: linear-gradient(110deg, transparent 25%, rgba(255,255,255,0.25) 50%, transparent 75%);
            background-size: 200% 100%;
            animation: shimmer 2.2s linear infinite;
        }
        .gold-text-gradient {
            background: linear-gradient(135deg, #e5c45c 0%, #c9a227 45%, #8a6b15 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
    </style>
</head>
<body class="bg-slate-50 text-primary font-sans min-h-screen w-full flex items-center justify-center p-4 sm:p-6 lg:p-8 overflow-x-hidden relative">

    <div class="w-full max-w-lg mx-auto relative z-10 animate-slide-up">

        <div class="absolute -top-2 right-0 z-20 flex items-center gap-1.5 bg-white/90 backdrop-blur p-1 rounded-xl border border-border shadow-lg">
            <a href="<?= $loginLangSuffix ?>lang=id"
               class="text-xs font-bold py-1 px-2.5 rounded-lg transition-all duration-200 <?= (APP_LANG === 'id') ? 'bg-gradient-to-r from-amber-500 to-amber-600 text-white shadow-gold-glow' : 'text-secondary hover:bg-slate-100 hover:text-primary' ?>">
                🇮🇩 ID
            </a>
            <a href="<?= $loginLangSuffix ?>lang=en"
               class="text-xs font-bold py-1 px-2.5 rounded-lg transition-all duration-200 <?= (APP_LANG === 'en') ? 'bg-gradient-to-r from-amber-500 to-amber-600 text-white shadow-gold-glow' : 'text-secondary hover:bg-slate-100 hover:text-primary' ?>">
                🇬🇧 EN
            </a>
        </div>

        <div class="bg-white rounded-[2rem] overflow-hidden shadow-premium-xl border border-border/70 p-6 sm:p-9 xl:p-11 relative">

            <div class="flex flex-col items-center justify-center text-center mb-8">
                <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-[1rem] bg-white p-1.5 shadow-xl ring-2 ring-amber-200/80 flex items-center justify-center animate-float flex-shrink-0 mb-3">
                    <img src="<?= BASE_URL ?>logo.jpeg" alt="Logo" class="w-full h-full object-cover rounded-lg" style="image-rendering:-webkit-optimize-contrast; image-rendering:crisp-edges; filter: drop-shadow(0 3px 8px rgba(0,0,0,0.28)) drop-shadow(0 1px 2px rgba(201,162,39,0.4));">
                </div>
                <div class="min-w-0">
                    <h2 class="font-display font-black text-primary text-lg sm:text-xl leading-tight">ST. REGIS BALI</h2>
                    <p class="text-[10px] font-bold tracking-[0.25em] uppercase text-accent mt-1"><?= T('app_subtitle', 'Engineering Daily Log') ?></p>
                </div>
            </div>

            <div class="mb-7">
                <h1 class="font-display text-2xl sm:text-3xl font-black text-primary leading-tight mb-1.5">
                    <?= T('wel_back', 'Selamat Datang') ?> <span class="gold-text-gradient"><?= APP_LANG === 'en' ? 'Back 👋' : 'Kembali 👋' ?></span>
                </h1>
                <p class="text-sm text-secondary mt-1"><?= T('login_subtitle', 'Silakan masukkan kredensial Anda untuk mengakses dashboard') ?></p>
            </div>

            <?php if ($error): ?>
                <div class="mb-6 pl-4 pr-4 py-3.5 rounded-card bg-gradient-to-r from-red-50 via-red-50 to-white border border-red-200 border-l-4 border-l-red-600 text-red-700 text-sm flex items-start gap-3 animate-shakeX shadow-sm">
                    <div class="w-8 h-8 rounded-full bg-red-100 border border-red-200 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-triangle-exclamation text-red-600 text-sm"></i>
                    </div>
                    <div>
                        <p class="font-bold text-red-800 mb-0.5"><?= APP_LANG === 'en' ? 'Login Failed' : 'Gagal Login' ?></p>
                        <p class="text-red-700/90 text-xs"><?= $error ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <div>
                    <label class="block text-xs font-bold text-primary mb-2.5 uppercase tracking-[0.18em]"><?= T('login_email', 'Email') ?> <span class="text-red-500">*</span></label>
                    <div class="group relative">
                        <span class="absolute left-0 top-0 bottom-0 w-12 flex items-center justify-center text-secondary group-focus-within:text-accent transition-colors pointer-events-none">
                            <i class="far fa-envelope"></i>
                        </span>
                        <input type="email" id="email" name="email" required autocomplete="email"
                            value="<?= isset($_POST['email']) ? cleanInput($_POST['email']) : '' ?>"
                            class="w-full pl-12 pr-4 py-3.5 rounded-card border-2 border-border bg-white text-primary placeholder-secondary/60 focus:outline-none focus:border-accent focus:ring-4 focus:ring-amber-500/10 transition-all duration-300 text-sm shadow-sm"
                            placeholder="nama.akun@stregisbali.com">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-2.5">
                        <label class="block text-xs font-bold text-primary uppercase tracking-[0.18em]"><?= T('login_password', 'Password') ?> <span class="text-red-500">*</span></label>
                        
                    </div>
                    <div class="group relative">
                        <span class="absolute left-0 top-0 bottom-0 w-12 flex items-center justify-center text-secondary group-focus-within:text-accent transition-colors pointer-events-none">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" id="password" name="password" required autocomplete="current-password"
                            class="w-full pl-12 pr-12 py-3.5 rounded-card border-2 border-border bg-white text-primary placeholder-secondary/60 focus:outline-none focus:border-accent focus:ring-4 focus:ring-amber-500/10 transition-all duration-300 text-sm shadow-sm"
                            placeholder="••••••••">
                        <button type="button" onclick="togglePassword()" class="absolute right-0 top-0 bottom-0 w-12 flex items-center justify-center text-secondary hover:text-accent transition-colors" aria-label="Toggle Password">
                            <i id="eyeIcon" class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

               

                <button type="submit"
                    class="relative w-full overflow-hidden py-4 rounded-card text-white font-bold shadow-gold-glow hover:shadow-[0_26px_55px_-14px_rgba(201,162,39,0.75)] hover:-translate-y-0.5 active:translate-y-0 transition-all duration-300 flex items-center justify-center gap-2.5 bg-gradient-to-r from-amber-500 via-amber-600 to-amber-700">
                    <span class="absolute inset-0 shimmer-btn"></span>
                    <span class="relative z-10 flex items-center gap-2.5">
                        <i class="fas fa-right-to-bracket group-hover:translate-x-1 transition-transform"></i>
                        <?= APP_LANG === 'en' ? 'Sign In to System' : 'Masuk ke Sistem' ?>
                    </span>
                </button>
            </form>

        

        </div>

        <div class="text-center mt-5 text-[11px] text-secondary">
            &copy; <?= date('Y') ?> St. Regis Bali — Engineering Division
        </div>
    </div>

    <script>
        function togglePassword() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
            setTimeout(() => pwd.focus(), 20);
        }

        function quickFill(email, password) {
            const inputEmail = document.getElementById('email');
            const inputPwd = document.getElementById('password');
            const focusEnd = (el) => { el.focus(); const v = el.value; el.value=''; setTimeout(()=>{el.value=v;},5); };
            if (inputEmail) { inputEmail.value = email; focusEnd(inputEmail); }
            if (inputPwd)   { inputPwd.value   = password; focusEnd(inputPwd); }
            if (window.innerWidth < 640) window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    </script>
</body>
</html>

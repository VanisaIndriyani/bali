<?php require_once __DIR__ . '/../config/config.php'; ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? $pageTitle . ' | ' : '' ?>St. Regis Bali - Engineering Daily Log</title>
    <link rel="icon" type="image/jpeg" href="<?= BASE_URL ?>logo.jpeg">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/style.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
                        display: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
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
                        'premium-xl': '0 25px 60px -20px rgba(17, 17, 17, 0.35), 0 8px 20px -8px rgba(17, 17, 17, 0.2)',
                        'gold-glow': '0 18px 40px -10px rgba(201, 162, 39, 0.55), 0 6px 14px -4px rgba(201, 162, 39, 0.25)',
                    },
                    animation: {
                        'pulse-glow': 'pulseGlow 2s ease-in-out infinite',
                        'shimmer': 'shimmer 2.2s linear infinite',
                        'fade-in': 'fadeIn 0.4s ease-out',
                        'slide-up': 'slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both',
                        'float': 'float 3.5s ease-in-out infinite',
                        'float-slow': 'float 6s ease-in-out infinite',
                        'shakeX': 'shakeX 0.5s ease-in-out both',
                        'spin-slow': 'spin 3s linear infinite',
                    },
                    keyframes: {
                        pulseGlow: {
                            '0%, 100%': { boxShadow: '0 0 8px rgba(201,162,39,0.45), 0 0 25px rgba(201,162,39,0.2)' },
                            '50%': { boxShadow: '0 0 25px rgba(201,162,39,0.7), 0 0 50px rgba(201,162,39,0.3)' },
                        },
                        shimmer: {
                            '0%': { backgroundPosition: '-1200px 0' },
                            '100%': { backgroundPosition: '1200px 0' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(28px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-10px)' },
                        },
                        shakeX: {
                            '0%,100%': { transform: 'translateX(0)' },
                            '10%,30%,50%,70%,90%': { transform: 'translateX(-6px)' },
                            '20%,40%,60%,80%': { transform: 'translateX(6px)' },
                        },
                    },
                }
            }
        }
    </script>
</head>
<body class="bg-muted text-primary font-sans min-h-screen">

<?php
// =============================================================================
// login.php - Premium Login Page with Full Screen Video Transitions
// =============================================================================

define('MONITOR_ROOT', __DIR__);
require_once MONITOR_ROOT . '/config.php';
require_once MONITOR_ROOT . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in
if (!empty($_SESSION['logged_in'])) {
    header('Location: index.php');
    exit;
}

$error    = '';
$redirect = preg_replace('/[^a-zA-Z0-9\/_\-\.?=]/', '', $_GET['redirect'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!hash_equals($_SESSION['login_token'] ?? '', $_POST['login_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } elseif (attemptLogin(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['user']      = trim($_POST['username']);
        unset($_SESSION['login_token']);
        header('Location: ' . $redirect);
        exit;
    } else {
        sleep(1);
        $error = 'Invalid username or password.';
    }
}

$_SESSION['login_token'] = bin2hex(random_bytes(32));
$token = $_SESSION['login_token'];

// High-quality video sources
$videos = [
    'https://cdn.coverr.co/videos/coverr-digital-data-stream-3171/1080p.mp4',
    'https://cdn.coverr.co/videos/coverr-network-hub-1583/1080p.mp4',
    'https://cdn.coverr.co/videos/coverr-server-room-2196/1080p.mp4',
    'https://cdn.coverr.co/videos/coverr-modern-city-at-night-2447/1080p.mp4',
    'https://cdn.coverr.co/videos/coverr-abstract-technology-background-3226/1080p.mp4'
];
$randomVideo = $videos[array_rand($videos)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Site Monitor | Premium Dashboard Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            height: 100vh;
            overflow: hidden;
            position: relative;
        }

        /* Video Container */
        .video-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            overflow: hidden;
        }

        .video-wrapper {
            position: relative;
            width: 100%;
            height: 100%;
        }

        #bg-video {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            min-width: 100%;
            min-height: 100%;
            width: auto;
            height: auto;
            object-fit: cover;
            transition: opacity 1.8s cubic-bezier(0.4, 0, 0.2, 1);
            will-change: opacity;
        }

        /* Gradient Overlay with Animation */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, 
                rgba(0, 0, 0, 0.85) 0%,
                rgba(0, 0, 0, 0.7) 25%,
                rgba(0, 0, 0, 0.65) 50%,
                rgba(0, 0, 0, 0.7) 75%,
                rgba(0, 0, 0, 0.85) 100%);
            z-index: -1;
            animation: gradientShift 8s ease-in-out infinite;
        }

        @keyframes gradientShift {
            0%, 100% {
                background: linear-gradient(135deg, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0.65) 50%, rgba(0,0,0,0.85) 100%);
            }
            50% {
                background: linear-gradient(225deg, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.6) 50%, rgba(0,0,0,0.8) 100%);
            }
        }

        /* Particle Canvas */
        #particle-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        /* Main Container */
        .login-wrapper {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        /* Glass Card */
        .login-card {
            background: rgba(10, 20, 40, 0.65);
            backdrop-filter: blur(20px);
            border-radius: 48px;
            padding: 56px 48px;
            width: 100%;
            max-width: 520px;
            border: 1px solid rgba(96, 165, 250, 0.3);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.05);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideUp 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .login-card:hover {
            transform: translateY(-8px);
            border-color: rgba(96, 165, 250, 0.6);
            box-shadow: 0 32px 64px -16px rgba(0, 0, 0, 0.6), 0 0 0 2px rgba(96, 165, 250, 0.2);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Logo Section */
        .logo-section {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo-icon {
            font-size: 72px;
            background: linear-gradient(135deg, #60a5fa, #a78bfa, #c084fc);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            animation: float 3s ease-in-out infinite;
            display: inline-block;
        }

        @keyframes float {
            0%, 100% {
                transform: translateY(0px);
            }
            50% {
                transform: translateY(-8px);
            }
        }

        .logo-text {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #94a3b8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: -0.5px;
            margin-top: 12px;
        }

        .logo-badge {
            display: inline-block;
            background: rgba(96, 165, 250, 0.2);
            padding: 4px 12px;
            border-radius: 40px;
            font-size: 11px;
            font-weight: 500;
            color: #60a5fa;
            margin-top: 12px;
            backdrop-filter: blur(4px);
        }

        /* Title */
        .title {
            font-size: 36px;
            font-weight: 800;
            text-align: center;
            background: linear-gradient(135deg, #fff, #cbd5e1);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 12px;
            letter-spacing: -1px;
        }

        .subtitle {
            text-align: center;
            color: #94a3b8;
            font-size: 14px;
            margin-bottom: 32px;
        }

        /* Error Message */
        .error-message {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.4);
            border-radius: 20px;
            padding: 14px 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: shake 0.5s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            75% { transform: translateX(6px); }
        }

        .error-message i {
            color: #ef4444;
            font-size: 18px;
        }

        .error-message span {
            color: #fecaca;
            font-size: 13px;
            flex: 1;
        }

        /* Form Groups */
        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .form-group label {
            display: block;
            color: #cbd5e1;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 8px;
            letter-spacing: 0.3px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 18px;
            transition: color 0.3s ease;
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px 14px 48px;
            background: rgba(15, 25, 45, 0.8);
            border: 1.5px solid rgba(148, 163, 184, 0.2);
            border-radius: 24px;
            color: #fff;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
            outline: none;
        }

        .form-control:focus {
            border-color: #60a5fa;
            background: rgba(15, 25, 45, 0.95);
            box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.15);
        }

        .form-control:focus + i {
            color: #60a5fa;
        }

        .form-control::placeholder {
            color: #475569;
        }

        /* Password Toggle */
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #64748b;
            transition: color 0.3s ease;
            z-index: 2;
        }

        .password-toggle:hover {
            color: #60a5fa;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            border: none;
            border-radius: 28px;
            color: white;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            margin-top: 8px;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.6s ease;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px -8px rgba(59, 130, 246, 0.5);
        }

        .login-btn:hover::before {
            left: 100%;
        }

        .login-btn:active {
            transform: translateY(0);
        }

        /* Footer */
        .footer {
            margin-top: 32px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }

        .footer a {
            color: #60a5fa;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer a:hover {
            color: #93c5fd;
        }

        .stats {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid rgba(148, 163, 184, 0.2);
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 20px;
            font-weight: 800;
            color: #60a5fa;
        }

        .stat-label {
            font-size: 10px;
            color: #64748b;
            margin-top: 4px;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .login-card {
                padding: 40px 28px;
                margin: 16px;
            }
            .title {
                font-size: 28px;
            }
            .logo-icon {
                font-size: 56px;
            }
            .logo-text {
                font-size: 24px;
            }
            .stats {
                gap: 16px;
            }
        }

        /* Loading Animation */
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(8px);
        }

        .loading.active {
            display: flex;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 3px solid rgba(96, 165, 250, 0.3);
            border-top-color: #60a5fa;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

<div class="video-container">
    <div class="video-wrapper">
        <video id="bg-video" autoplay playsinline muted loop style="opacity:0;">
            <source src="<?= htmlspecialchars($randomVideo) ?>" type="video/mp4">
        </video>
    </div>
</div>
<div class="overlay"></div>
<canvas id="particle-canvas"></canvas>

<div class="login-wrapper">
    <div class="login-card">
        <div class="logo-section">
            <div class="logo-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="logo-text">Site Monitor</div>
            <div class="logo-badge">
                <i class="fas fa-shield-alt"></i> Enterprise Monitoring
            </div>
        </div>

        <div class="title">Welcome Back</div>
        <div class="subtitle">Sign in to access your dashboard</div>

        <?php if ($error): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-triangle"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="login.php<?= $redirect !== 'index.php' ? '?redirect=' . urlencode($redirect) : '' ?>" id="loginForm">
            <input type="hidden" name="login_token" value="<?= htmlspecialchars($token) ?>">
            
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" class="form-control" 
                           required autocomplete="username" placeholder="Enter your username"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" class="form-control" 
                           required autocomplete="current-password" placeholder="Enter your password">
                    <i class="fas fa-eye password-toggle" onclick="togglePassword()"></i>
                </div>
            </div>

            <button type="submit" class="login-btn">
                <i class="fas fa-arrow-right-to-bracket"></i> Sign In
            </button>
        </form>

        <div class="stats">
            <div class="stat-item">
                <div class="stat-number">53</div>
                <div class="stat-label">Active Sites</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">24/7</div>
                <div class="stat-label">Monitoring</div>
            </div>
            <div class="stat-item">
                <div class="stat-number">Instant</div>
                <div class="stat-label">Alerts</div>
            </div>
        </div>

        <div class="footer">
            <p>🔒 Secure Login | <i class="fas fa-shield-alt"></i> SSL Encrypted</p>
            <p style="margin-top: 8px">© 2024 Site Monitor - Real-time Infrastructure Monitoring</p>
        </div>
    </div>
</div>

<div class="loading" id="loading">
    <div class="spinner"></div>
</div>

<script>
    // Video transition with rotation
    const videos = <?php echo json_encode($videos); ?>;
    let currentVideoIndex = <?php echo array_search($randomVideo, $videos); ?>;
    const videoElement = document.getElementById('bg-video');
    
    function rotateVideo() {
        videoElement.style.opacity = '0';
        setTimeout(() => {
            currentVideoIndex = (currentVideoIndex + 1) % videos.length;
            videoElement.src = videos[currentVideoIndex];
            videoElement.load();
            videoElement.play().catch(e => console.log('Autoplay prevented'));
            videoElement.style.opacity = '1';
        }, 800);
    }
    
    // Rotate video every 12 seconds
    setInterval(rotateVideo, 12000);
    
    // Fade in video on load
    videoElement.addEventListener('loadeddata', () => {
        videoElement.style.opacity = '1';
    });
    
    // Particle System
    const canvas = document.getElementById('particle-canvas');
    const ctx = canvas.getContext('2d');
    let particles = [];
    let particleCount = 80;
    
    function resizeCanvas() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
    }
    
    function createParticles() {
        for (let i = 0; i < particleCount; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: Math.random() * canvas.height,
                radius: Math.random() * 2 + 1,
                speedX: (Math.random() - 0.5) * 0.5,
                speedY: (Math.random() - 0.5) * 0.3,
                opacity: Math.random() * 0.5 + 0.2
            });
        }
    }
    
    function animateParticles() {
        if (!ctx) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        
        for (let i = 0; i < particles.length; i++) {
            const p = particles[i];
            ctx.beginPath();
            ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(96, 165, 250, ${p.opacity})`;
            ctx.fill();
            
            p.x += p.speedX;
            p.y += p.speedY;
            
            if (p.x < 0) p.x = canvas.width;
            if (p.x > canvas.width) p.x = 0;
            if (p.y < 0) p.y = canvas.height;
            if (p.y > canvas.height) p.y = 0;
        }
        
        requestAnimationFrame(animateParticles);
    }
    
    // Password visibility toggle
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.querySelector('.password-toggle');
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }
    
    // Form loading state
    document.getElementById('loginForm').addEventListener('submit', function() {
        document.getElementById('loading').classList.add('active');
    });
    
    // Initialize
    resizeCanvas();
    createParticles();
    animateParticles();
    window.addEventListener('resize', resizeCanvas);
    
    // Random floating particles effect
    setInterval(() => {
        for (let i = 0; i < 5; i++) {
            particles.push({
                x: Math.random() * canvas.width,
                y: canvas.height + 10,
                radius: Math.random() * 3 + 1,
                speedX: (Math.random() - 0.5) * 0.8,
                speedY: Math.random() * -2 - 1,
                opacity: Math.random() * 0.6 + 0.3
            });
        }
        if (particles.length > 150) particles = particles.slice(-120);
    }, 2000);
</script>

</body>
</html>

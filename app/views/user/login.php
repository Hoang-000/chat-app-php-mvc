<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Messages</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #121212;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: #1e1e1e;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            width: 100%;
            max-width: 420px;
            padding: 48px 40px;
        }

        .login-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .logo {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, #d47d16 0%, #ff9933 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
            box-shadow: 0 4px 16px rgba(212, 125, 22, 0.3);
        }

        .logo::before {
            content: '💬';
        }

        .login-header h1 {
            font-size: 28px;
            color: #ffffff;
            margin-bottom: 8px;
            font-weight: 600;
            letter-spacing: -0.5px;
        }

        .login-header p {
            color: #999999;
            font-size: 14px;
            font-weight: 400;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #cccccc;
            font-weight: 500;
            font-size: 14px;
        }

        .form-group select {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #333333;
            border-radius: 10px;
            font-size: 15px;
            color: #ffffff;
            background: #2a2a2a;
            cursor: pointer;
            transition: all 0.3s ease;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23999999' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }

        .form-group select:focus {
            outline: none;
            border-color: #d47d16;
            background-color: #2f2f2f;
            box-shadow: 0 0 0 3px rgba(212, 125, 22, 0.15);
        }

        .form-group select option {
            background: #2a2a2a;
            color: #ffffff;
            padding: 12px;
        }

        .form-group select option:first-child {
            color: #666666;
        }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #d47d16 0%, #ff9933 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(212, 125, 22, 0.25);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(212, 125, 22, 0.4);
            background: linear-gradient(135deg, #e68a1f 0%, #ffaa44 100%);
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(212, 125, 22, 0.3);
        }

        .error-message {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ff6b6b;
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 24px;
            font-size: 14px;
            text-align: center;
            line-height: 1.5;
        }

        .footer-text {
            text-align: center;
            margin-top: 28px;
            color: #666666;
            font-size: 13px;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 36px 28px;
            }

            .login-header h1 {
                font-size: 24px;
            }

            .logo {
                width: 56px;
                height: 56px;
                font-size: 28px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo"></div>
            <h1>Messages</h1>
            <p>Chọn tài khoản để đăng nhập</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="error-message">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php?controller=user&action=login">
            <div class="form-group">
                <label for="user_id">Chọn tài khoản</label>
                <select name="user_id" id="user_id" required>
                    <option value="">-- Chọn user --</option>
                    <?php if (!empty($users)): ?>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user->getId() ?>">
                                <?= htmlspecialchars($user->getName()) ?> (ID: <?= $user->getId() ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <button type="submit" class="btn-login">
                Login
            </button>
        </form>

        <div class="footer-text">
            MVP Login • No Password Required
        </div>
    </div>
</body>
</html>

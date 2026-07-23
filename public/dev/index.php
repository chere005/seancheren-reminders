<?php
session_start();

// Change these credentials before deploying
define('USERNAME', 'admin');
define('PASSWORD_HASH', password_hash('changeme', PASSWORD_DEFAULT));

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        isset($_POST['username'], $_POST['password']) &&
        $_POST['username'] === USERNAME &&
        password_verify($_POST['password'], PASSWORD_HASH)
    ) {
        $_SESSION['auth'] = true;
        header('Location: /dev/');
        exit;
    }
    $error = 'Invalid username or password.';
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /dev/');
    exit;
}

$authenticated = !empty($_SESSION['auth']);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $authenticated ? 'Sketch' : 'Login' ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: system-ui, sans-serif;
      background: #111;
      color: #eee;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }

    /* Login */
    .login-box {
      background: #1a1a1a;
      border: 1px solid #333;
      border-radius: 8px;
      padding: 2rem;
      width: 100%;
      max-width: 320px;
    }
    .login-box h1 { font-size: 1.25rem; margin-bottom: 1.5rem; text-align: center; }
    .login-box label { display: block; font-size: 0.8rem; color: #aaa; margin-bottom: 0.25rem; }
    .login-box input {
      width: 100%;
      padding: 0.5rem 0.75rem;
      background: #222;
      border: 1px solid #444;
      border-radius: 4px;
      color: #eee;
      font-size: 1rem;
      margin-bottom: 1rem;
    }
    .login-box input:focus { outline: none; border-color: #888; }
    .login-box button {
      width: 100%;
      padding: 0.6rem;
      background: #eee;
      color: #111;
      border: none;
      border-radius: 4px;
      font-size: 1rem;
      cursor: pointer;
    }
    .login-box button:hover { background: #fff; }
    .error { color: #f66; font-size: 0.85rem; margin-top: 0.75rem; text-align: center; }

    /* Sketch */
    canvas { display: block; background: #fff; }
    nav {
      position: fixed;
      top: 0.75rem;
      right: 1rem;
      font-size: 0.8rem;
    }
    nav a { color: #555; text-decoration: none; }
    nav a:hover { color: #000; }
  </style>
</head>
<body>

<?php if (!$authenticated): ?>
  <div class="login-box">
    <h1>Sign in</h1>
    <form method="post" action="/dev/">
      <label for="username">Username</label>
      <input id="username" type="text" name="username" autocomplete="username" required>
      <label for="password">Password</label>
      <input id="password" type="password" name="password" autocomplete="current-password" required>
      <button type="submit">Log in</button>
    </form>
    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
  </div>

<?php else: ?>
  <nav><a href="/dev/?logout">Log out</a></nav>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/p5.js/1.11.3/p5.min.js"></script>
  <script src="/dev/sketch.js"></script>
<?php endif; ?>

</body>
</html>

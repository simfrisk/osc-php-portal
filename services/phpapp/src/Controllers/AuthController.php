<?php

namespace App\Controllers;

use App\Auth;
use App\Csrf;
use App\Views;

class AuthController
{
    public static function showLogin(): void
    {
        $csrf = Csrf::field();
        $error = isset($_GET['error']) ? '<p class="error">Wrong username or password.</p>' : '';
        Views::layout('Log in', <<<HTML
<h1>Log in</h1>
{$error}
<form method="post" action="/login">
  {$csrf}
  <label for="username">Username</label>
  <input type="text" id="username" name="username" required autofocus>
  <label for="password">Password</label>
  <input type="password" id="password" name="password" required>
  <button class="btn" type="submit">Log in</button>
</form>
HTML);
    }

    public static function login(): void
    {
        if (!Csrf::verify($_POST['csrf_token'] ?? null)) {
            http_response_code(400);
            echo 'Invalid form submission (CSRF token mismatch). Go back and try again.';
            return;
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (Auth::attempt($username, $password)) {
            header('Location: /contacts');
            return;
        }

        header('Location: /login?error=1');
    }

    public static function logout(): void
    {
        Auth::logout();
        header('Location: /login');
    }
}

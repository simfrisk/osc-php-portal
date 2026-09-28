<?php

namespace App;

class Views
{
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES);
    }

    public static function layout(string $title, string $body): void
    {
        $loggedIn = Auth::check();
        $username = self::e(Auth::username());
        $title = self::e($title);
        $navAuth = $loggedIn
            ? "<span>Logged in as {$username}</span> <a href=\"/logout\">Log out</a>"
            : "<a href=\"/login\">Log in</a>";
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title} - OSC PHP Portal</title>
<style>
  body { font-family: -apple-system, Arial, sans-serif; max-width: 780px; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; }
  nav a { margin-right: 1rem; }
  table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
  th, td { text-align: left; padding: 0.4rem 0.6rem; border-bottom: 1px solid #ddd; }
  input, textarea { display: block; width: 100%; padding: 0.4rem; margin-bottom: 0.6rem; box-sizing: border-box; }
  label { font-weight: bold; margin-top: 0.6rem; display: block; }
  .btn { display: inline-block; padding: 0.4rem 0.8rem; background: #2b6cb0; color: #fff; text-decoration: none; border: none; cursor: pointer; border-radius: 4px; }
  .btn-danger { background: #c53030; }
  .flash { background: #edf7ed; border: 1px solid #9ae6b4; padding: 0.6rem; margin-bottom: 1rem; }
  .error { background: #fdecea; border: 1px solid #f5b5b0; padding: 0.6rem; margin-bottom: 1rem; }
  code { background: #f4f4f4; padding: 0.1rem 0.3rem; }
</style>
</head>
<body>
<nav>
  <a href="/contacts">Contacts</a>
  <a href="/status">Status</a>
  {$navAuth}
</nav>
<hr>
{$body}
</body>
</html>
HTML;
    }
}

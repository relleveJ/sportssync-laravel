

## What is SportSync?
SportSync is a web application for managing sports events, matches, and simple admin tools. It uses Laravel (a PHP web framework) to organize the code, secure pages, and make it easier to add features. SportSync also includes older (legacy) PHP pages that are gradually being moved into Laravel.

Think of SportSync as a control center where admins can create matches, update scores, view activity logs, and manage users and sports.

---

## Why Laravel and Composer?
- Laravel: A toolkit for building PHP web apps quickly and safely. It gives structure (routes, controllers, views), handles common tasks (authentication, sessions, middleware), and helps keep code organized.

- Composer: The package manager for PHP. Composer installs libraries (like Laravel) and keeps them up to date so your project can use third-party tools safely.

Short analogy: Composer is like a toy box with extra Lego pieces; Laravel is the instruction booklet that tells you how to build a strong model fast.

---

## Live updates: WebSocket, Broadcast, LocalStorage
- WebSocket: A continuous connection between the browser and server so the server can push updates right away (no page reload). SportSync includes a small WebSocket server (e.g., under `public/ws-server`) to push live match updates.

- Broadcast: A way the server signals "an event happened" (like "score changed") and tells the WebSocket server to send that event to all listening browsers. In Laravel this is usually done with events + broadcasting (Laravel events -> broadcaster -> WebSocket clients).

- localStorage: A tiny place in the browser where small data can be stored (like a last-opened page or a preference). It's not a database — it just helps the UI remember things between page reloads.

How they work together: When a match score is saved, the server writes the data to the database, broadcasts an event to the WebSocket server, and every browser listening to that sport receives the update and refreshes the displayed score. localStorage is only used for small client-side caching or UI settings.

---

## What algorithms does SportSync use?
SportSync mostly uses simple, reliable algorithms:
- Pub/Sub (publish/subscribe): server publishes events; clients subscribed to channels receive them in real time.
- SQL queries for counting, sorting, and filtering (e.g., `COUNT(*)`, `ORDER BY created_at DESC`).
- Timestamp ordering: newest items are shown first by sorting on time fields.
- De-duplication by unique IDs: each match or event has an ID so duplicate messages can be ignored.

If you need a specific feature's algorithm (ranking, scheduling, or conflict resolution), tell me which feature and I will explain that exact logic.

---

## Simple explanations of key parts (like you're 6 years old)
- Controller: The controller is like the person at a shop counter — you ask for something (a page or action), they get it for you or make it happen.

- Routes: Routes are the addresses or signs that tell the app which controller should handle a website address (URL).

- Layout (Blade templates): A layout is the page frame (header, footer) used by many pages so they all look the same. Blade is Laravel's tool to make these templates easy.

- `.env` file: A secret recipe card that stores passwords, database details, and keys. Keep it private — do not share it.

- API: A set of doors that other programs use to talk to SportSync (send data or get data back) without using the website directly.

- Auth (Authentication): The login system — it checks who you are and whether you are allowed to do things.

- Middleware: Little guards that check requests before they reach controllers (for example: "Are you logged in?" or "Do you have the admin role?").

- View: What the user sees — the HTML and CSS created by the server that becomes the web page.

- Public folder: The front door of the website — anything in `public/` can be served directly to the browser (images, JS, CSS, and the index page).

---

## Where do the legacy pages and DB helpers live?
- Legacy PHP pages may be found in the `public/` folder and are proxied into Laravel when needed.

- The legacy DB helper used by many legacy pages is `app/Legacy/db.php` which provides a PDO `$pdo` connection to the `sportssync` database.

---

## Very simple step-by-step: Install Composer and create a Laravel project (Windows)
(These steps assume you have PHP installed. If you don't, ask and I will give the PHP steps.)

1) Install Composer (easy steps):
- Open your web browser and go to: https://getcomposer.org/download/
- Click the link to download "Composer-Setup.exe" (the installer for Windows).
- When the file finishes downloading, open it and follow the installer steps (click "Next", allow it to set the PATH). This installs Composer so you can use `composer` in the terminal.
- To check it works: open PowerShell or Command Prompt and type:

```
composer -V
```

You should see a version number if it worked.

2) Create a new Laravel project (super simple):
- Open PowerShell in the folder where you want your project. Then type:

```
composer create-project laravel/laravel myapp
cd myapp
php artisan serve
```

- `composer create-project` downloads Laravel and prepares a new app folder called `myapp`.
- `php artisan serve` starts a small local web server. Open the browser and go to `http://localhost:8000` to see the site.

Alternative (install Laravel installer):
```
composer global require laravel/installer
laravel new myapp
```

That gives a `myapp` folder with Laravel ready.

If any step shows an error, copy the error text and I'll help you fix it.

---

## Final notes
- This README is an overview. SportSync contains both modern Laravel code and legacy PHP files. When editing or moving legacy code, avoid printing headers or starting sessions after output is sent (this is why we use a proxy/middleware approach).
- If you want, I can:
  - Add a short architecture diagram.
  - Create a short quickstart for running SportSync locally (DB settings, seeder, and how to run the WebSocket server).
  - Explain any particular file or feature in more detail.

---

Thanks — tell me which part you want expanded or if you want the README saved as `README.md` instead of `README_SPORTSYNC.md`.

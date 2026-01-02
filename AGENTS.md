# Project Context: BPMS (Beauty Pageant Management System)
This is a native PHP application for managing beauty pageants. It relies on vanilla PHP without any frameworks (No Laravel, No CodeIgniter). No Libraries, no templates. That is the rule.

# Project Structure & Architecture
* **Database Config:** `app/config/database.php` (Defines `$conn` variable).
* **Backend API:** `api/` folder contains PHP scripts that handle AJAX requests and return JSON.
* **Business Logic/Models:** `app/models/` contains classes (e.g., `ScoreCalculator.php`, `Contestant.php`).
* **Frontend Pages:** `public/` contains the user-facing PHP/HTML files.
* **Styles:** `public/assets/css/style.css` is the main stylesheet.
* **Authentication:** `api/auth.php` handles login logic; `app/core/guard.php` manages session protection.

# Coding Rules
1.  **Database Interaction:** * ALWAYS use the `mysqli` extension. 
    * NEVER use PDO. 
    * Use the existing `$conn` connection variable from `app/config/database.php`.
    * Example include: `require_once __DIR__ . '/../app/config/database.php';` (adjust path as needed).

2.  **Frontend:**
    * Use vanilla JavaScript for interactivity (no React/Vue).
    * Keep styles in `public/assets/css/style.css` rather than inline styles where possible.

3.  **Key Directories:**
    * Images are stored in: `public/assets/uploads/contestants/`.
    * Core logic (like mailing) is in: `app/core/`.

# Do Not
* Do not install Composer packages.
* Do not change the folder structure.
* Do not mix API logic (JSON output) with View logic (HTML output) in the `api/` folder.
* Do not use any templating engines (e.g., Twig, Blade).
* Do not use any PHP frameworks.
* Do not use any CSS frameworks (e.g., Bootstrap, Tailwind).
* Do not use any JavaScript frameworks (e.g., React, Vue).
* Do not use any PHP libraries (e.g., PHPMailer, Carbon).


# Do
* Use plain PHP for backend logic.
* Use plain HTML/CSS/JavaScript for frontend.
* Use plain SQL for database queries.
* Use plain PHP for API endpoints.
* Use plain PHP for models.
* Use plain PHP for controllers.
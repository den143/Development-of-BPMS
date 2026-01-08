## 2026-01-08 - Optimized Score Calculation
**Learning:** In PHP/MySQL applications, replacing `WHERE IN (SELECT ...)` subqueries with PHP-generated ID lists can significantly improve performance, especially when the outer query is executed frequently. Also, selecting only specific columns instead of `SELECT *` reduces memory usage and data transfer overhead.
**Action:** Always check for `SELECT *` and dependent subqueries in performance-critical paths. Verify that ID lists are not empty before constructing the query to avoid SQL errors.

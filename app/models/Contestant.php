<?php
require_once __DIR__ . '/../config/database.php';

class Contestant
{
    private static function db() {
        global $conn;
        return $conn;
    }

    public static function getAllByManager(int $managerId, string $status, string $search = ''): array
    {
        $db = self::db();

        // [FIX] Added "AND e.status = 'Active'" to the WHERE clause.
        // This ensures we only get contestants linked to the currently open event.
        $sql = "SELECT u.id, u.name, u.email, u.status, 
                       cd.age, cd.height, cd.vital_stats, cd.hometown, cd.motto, cd.photo, cd.event_id,
                       e.name as event_name 
                FROM users u 
                JOIN contestant_details cd ON u.id = cd.user_id 
                JOIN events e ON cd.event_id = e.id 
                WHERE e.user_id = ? 
                  AND e.status = 'Active' 
                  AND u.role = 'Contestant' 
                  AND u.status = ?";

        $types = "is";
        $params = [$managerId, $status];

        if (!empty($search)) {
            $sql .= " AND (u.name LIKE ? OR cd.hometown LIKE ?)";
            $types .= "ss";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $sql .= " ORDER BY u.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
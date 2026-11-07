-- Optional repair for missing room records only. Safe to run repeatedly on an existing schema.
-- Existing room IDs, activation decisions, service links and appointment records remain unchanged.
-- Missing/inactive services or conflicting room mappings require administrator review.
USE bella_vista_spa;
INSERT INTO rooms (service_id,code,capacity,is_active)
SELECT s.id,required.code,required.capacity,1
FROM (
 SELECT 'SR-1' AS code,'single-room-massage' AS slug,1 AS capacity UNION ALL
 SELECT 'SR-2','single-room-massage',1 UNION ALL SELECT 'SR-3','single-room-massage',1 UNION ALL
 SELECT 'CS-1','couples-suite',2 UNION ALL SELECT 'CS-2','couples-suite',2 UNION ALL
 SELECT 'FL-1','family-lounge',6 UNION ALL SELECT 'FL-2','family-lounge',6
) AS required
JOIN services s ON s.slug=required.slug AND s.is_active=1
WHERE NOT EXISTS (SELECT 1 FROM rooms r WHERE r.code=required.code)
ON DUPLICATE KEY UPDATE code=rooms.code;
